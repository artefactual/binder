<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Manage aips in search index
 *
 * @package    AccesstoMemory
 * @subpackage arElasticSearchPlugin
 */
class arElasticSearchAipPdo
{
  public
    $i18ns;

  protected
    $data = array();

  protected static
    $conn,
    $lookups,
    $statements;

  /**
   * METHODS
   */
  public function __construct($id, $options = array())
  {
    if (isset($options['conn']))
    {
      self::$conn = $options['conn'];
    }

    if (!isset(self::$conn))
    {
      self::$conn = Propel::getConnection();
    }

    $this->loadData($id, $options);
  }

  public function __isset($name)
  {
    return isset($this->data[$name]);
  }

  public function __get($name)
  {
    if (isset($this->data[$name]))
    {
      return $this->data[$name];
    }
  }

  public function __set($name, $value)
  {
    $this->data[$name] = $value;
  }

  protected function loadData($id)
  {
    if (!isset(self::$statements['aip']))
    {
      $sql  = 'SELECT *';
      $sql .= ' FROM '.QubitAip::TABLE_NAME;
      $sql .= ' WHERE id = :id';

      self::$statements['aip'] = self::$conn->prepare($sql);
    }

    // Do select
    self::$statements['aip']->execute(array(':id' => $id));

    // Get first result
    $this->data = self::$statements['aip']->fetch(PDO::FETCH_ASSOC);

    if (false === $this->data)
    {
      throw new sfException("Couldn't find aip (id: $id)");
    }

    self::$statements['aip']->closeCursor();

    return $this;
  }

  protected function getDigitalObjects()
  {
    $sql  = 'SELECT
                prop.object_id';
    $sql .= ' FROM '.QubitProperty::TABLE_NAME.' prop';
    $sql .= ' JOIN '.QubitPropertyI18n::TABLE_NAME.' prop_i18n
                ON prop.id = prop_i18n.id';
    $sql .= ' WHERE prop_i18n.value = ?
                AND prop.name = ?';

    self::$statements['do'] = self::$conn->prepare($sql);
    self::$statements['do']->execute(array($this->uuid, 'aipUUID'));

    $digitalObejcts = array();

    foreach (self::$statements['do']->fetchAll(PDO::FETCH_OBJ) as $item)
    {
      if (null !== $premisData = arElasticSearchPluginUtil::getPremisData($item->object_id, self::$conn))
      {
        $digitalObjects[] = array('metsData' => $premisData);
      }
    }

    if (!empty($digitalObjects))
    {
      return $digitalObjects;
    }
  }

  protected function getPartOfLevelOfDescriptionId($id)
  {
    $sql  = 'SELECT
                level_of_description_id';
    $sql .= ' FROM '.QubitInformationObject::TABLE_NAME;
    $sql .= ' WHERE id = ?';

    self::$statements['partOf'] = self::$conn->prepare($sql);
    self::$statements['partOf']->execute(array($id));

    return self::$statements['partOf']->fetchColumn();
  }

  protected function getProperty($name)
  {
    $sql  = 'SELECT
                i18n.value';
    $sql .= ' FROM '.QubitProperty::TABLE_NAME.' node';
    $sql .= ' JOIN '.QubitPropertyI18n::TABLE_NAME.' i18n
                ON node.id = i18n.id';
    $sql .= ' WHERE node.source_culture = i18n.culture
                AND node.object_id = ?
                AND node.name = ?';

    self::$statements['property'] = self::$conn->prepare($sql);
    self::$statements['property']->execute(array($this->__get('id'), $name));
    $result = self::$statements['property']->fetch(PDO::FETCH_ASSOC);

    if(false !== $result)
    {
      return $result['value'];
    }
  }

  protected function getPartOfYearCollected($id)
  {
    $sql  = 'SELECT
                i18n.value';
    $sql .= ' FROM '.QubitProperty::TABLE_NAME.' node';
    $sql .= ' JOIN '.QubitPropertyI18n::TABLE_NAME.' i18n
                ON node.id = i18n.id';
    $sql .= ' WHERE node.source_culture = i18n.culture
                AND node.object_id = ?
                AND node.name = ?';

    self::$statements['property'] = self::$conn->prepare($sql);
    self::$statements['property']->execute(array($id, 'AccessionISODate'));
    $result = self::$statements['property']->fetch(PDO::FETCH_ASSOC);

    if(false !== $result)
    {
      $dateComponents = date_parse($result['value']);
      return $dateComponents['year'];
    }
  }

  protected function getPartOfDepartments()
  {
    $sql  = 'SELECT
                current.id as id,
                i18n.name as name';
    $sql .= ' FROM '.QubitObjectTermRelation::TABLE_NAME.' otr';
    $sql .= ' JOIN '.QubitTerm::TABLE_NAME.' current
                ON otr.term_id = current.id';
    $sql .= ' JOIN '.QubitTermI18n::TABLE_NAME.' i18n
                ON otr.term_id = i18n.id';
    $sql .= ' WHERE otr.object_id = ?
                AND current.taxonomy_id = ?
                AND i18n.culture = ?';

    self::$statements['parOfDepartments'] = self::$conn->prepare($sql);
    self::$statements['parOfDepartments']->execute(array($this->part_of, sfConfig::get('app_drmc_taxonomy_departments_id'), 'en'));

    return self::$statements['parOfDepartments']->fetchAll(PDO::FETCH_OBJ);
  }

  public function serialize()
  {
    $serialized = array();

    $serialized['id'] = $this->id;
    $serialized['uuid'] = $this->uuid;
    $serialized['filename'] = $this->filename;
    $serialized['sizeOnDisk'] = $this->size_on_disk;
    $serialized['digitalObjectCount'] = $this->digital_object_count;
    $serialized['createdAt'] = arElasticSearchPluginUtil::convertDate($this->created_at);

    if (null !== $this->type_id)
    {
      $node = new arElasticSearchTermPdo($this->type_id);
      $serialized['type'] = $node->serialize();
    }

    if (null !== $this->part_of)
    {
      $serialized['partOf']['id'] = $this->part_of;
      $serialized['partOf']['i18n'] = arElasticSearchModelBase::serializeI18ns($this->part_of, array('QubitInformationObject'), array('fields' => array('title')));

      if (null !== $lod = $this->getPartOfLevelOfDescriptionId($this->part_of))
      {
        $serialized['partOf']['levelOfDescriptionId'] = $lod;
      }

      if (null !== $yearCollected = $this->getPartOfYearCollected($this->part_of))
      {
        $serialized['partOf']['year_collected'] = (int)$yearCollected;
      }

      if (0 < count($departments = $this->getPartOfDepartments()))
      {
        if (null !== $id = $departments[0]->id)
        {
          $serialized['partOf']['department']['id'] = $id;
        }

        if (null !== $name = $departments[0]->name)
        {
          $serialized['partOf']['department']['name'] = $name;
        }
      }
    }

    if (null !== $digitalObjects = $this->getDigitalObjects())
    {
      $serialized['digitalObjects'] = $digitalObjects;
    }

    if (null !== $ingestionUser = $this->getProperty('ingestionUser'))
    {
      $serialized['ingestionUser'] = $ingestionUser;
    }

    if (null !== $attachedTo = $this->getProperty('attachedTo'))
    {
      $serialized['attachedTo'] = $attachedTo;
    }

    return $serialized;
  }
}
