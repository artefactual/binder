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
 * Manage information objects in search index
 *
 * @package    AccesstoMemory
 * @subpackage arElasticSearchPlugin
 * @author     David Juhasz <david@artefactual.com>
 */
class arElasticSearchInformationObjectPdo
{
  public
    $ancestors,
    $doc,
    $repository,
    $sourceCulture;

  protected
    $data = array(),
    $events,
    $languages = array(),
    $scripts = array();

  protected static
    $conn,
    $lookups,
    $statements;

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

    // Get inherited ancestors
    if (isset($options['ancestors']))
    {
      $this->ancestors = $options['ancestors'];
    }

    // Get inherited repository, unless a repository is set at current level
    if (isset($options['repository']) && !$this->__isset('repository_id'))
    {
      $this->repository = $options['repository'];
    }
  }

  public function __isset($name)
  {
    if ('events' == $name)
    {
      return isset($this->events);
    }

    return isset($this->data[$name]);
  }

  public function __get($name)
  {
    if ('events' == $name)
    {
      return $this->events;
    }

    if (isset($this->data[$name]))
    {
      return $this->data[$name];
    }
  }

  public function __set($name, $value)
  {
    if ('events' == $name)
    {
      $this->events = $value;

      return;
    }

    $this->data[$name] = $value;
  }

  protected function loadData($id, $options = array())
  {
    if (!isset(self::$statements['informationObject']))
    {
      $sql = 'SELECT
         io.*,
         obj.created_at,
         obj.updated_at,
         slug.slug,
         pubstat.status_id as publication_status_id,
         do.id as digital_object_id,
         do.media_type_id as media_type_id,
         do.mime_type as mime_type,
         do.byte_size as byte_size,
         do.name as filename,
         do.checksum as checksum
       FROM '.QubitInformationObject::TABLE_NAME.' io
       JOIN '.QubitObject::TABLE_NAME.' obj
         ON io.id = obj.id
       JOIN '.QubitSlug::TABLE_NAME.' slug
         ON io.id = slug.object_id
       JOIN '.QubitStatus::TABLE_NAME.' pubstat
         ON io.id = pubstat.object_id
       LEFT JOIN '.QubitDigitalObject::TABLE_NAME.' do
         ON io.id = do.information_object_id
       WHERE io.id = :id';

      self::$statements['informationObject'] = self::$conn->prepare($sql);
    }

    // Do select
    self::$statements['informationObject']->execute(array(':id' => $id));

    // Get first result
    $this->data = self::$statements['informationObject']->fetch(PDO::FETCH_ASSOC);

    if (false === $this->data)
    {
      throw new sfException("Couldn't find information object (id: $id)");
    }

    // Load event data
    $this->loadEvents();

    return $this;
  }

  /**
   * Return an array of ancestors
   *
   * @return array of ancestors
   */
  public function getAncestors()
  {
    if (!isset($this->ancestors))
    {
      // Find ancestors
      $sql  = 'SELECT
                  node.id,
                  identifier,
                  repository_id,
                  slug';
      $sql .= ' FROM '.QubitInformationObject::TABLE_NAME.' node';
      $sql .= ' JOIN '.QubitSlug::TABLE_NAME.' slug
                  ON node.id = slug.object_id';
      $sql .= ' WHERE node.lft < ? AND node.rgt > ?';
      $sql .= ' ORDER BY lft';

      $this->ancestors = QubitPdo::fetchAll($sql, array($this->__get('lft'), $this->__get('rgt')));
    }

    if (!isset($this->ancestors) || 0 == count($this->ancestors))
    {
      throw new sfException(sprintf("%s: Couldn't find ancestors, please make sure lft and rgt values are correct", get_class($this)));
    }

    return $this->ancestors;
  }

  /**
   * Return an array of children
   *
   * @return array of children
   */
  public function getChildren()
  {
    if (!isset($this->children))
    {
      // Find children
      $sql  = 'SELECT
                  node.id';
      $sql .= ' FROM '.QubitInformationObject::TABLE_NAME.' node';
      $sql .= ' WHERE node.parent_id = :id';
      $sql .= ' ORDER BY lft';

      $this->children = QubitPdo::fetchAll($sql, array(':id' => $this->id));
    }

    return $this->children;
  }

  /**
   * Return the closest repository
   *
   * @return QubitRepository
   */
  public function getRepository()
  {
    if (!isset($this->repository))
    {
      if ($this->__isset('repository_id'))
      {
        $this->repository = QubitRepository::getById($this->__get('repository_id'));
      }
      else
      {
        foreach (array_reverse($this->ancestors) as $item)
        {
          if (isset($item->repository_id))
          {
            $this->repository = QubitRepository::getById($item->repository_id);

            break;
          }
        }
      }
    }

    return $this->repository;
  }

  public function getCollectionRoot()
  {
    if (QubitInformationObject::ROOT_ID == $this->__get('parent_id'))
    {
      return QubitInformationObject::getById($this->__get('id'));
    }
    else
    {
      return QubitInformationObject::getById($this->ancestors[1]->id);
    }
  }

  public function getLevelOfDescription($culture)
  {
    if (!isset(self::$lookups['levelOfDescription']))
    {
      $criteria = new Criteria;
      $criteria->add(QubitTerm::TAXONOMY_ID, QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID);
      foreach (QubitTerm::get($criteria) as $item)
      {
        self::$lookups['levelOfDescription'][$item->id] = $item;
      }
    }

    if (isset(self::$lookups['levelOfDescription'][$this->__get('level_of_description_id')]))
    {
      return self::$lookups['levelOfDescription'][$this->__get('level_of_description_id')]->getName(array(
        'culture' => $culture,
        'fallback' => true));
    }
  }

  public function getMediaTypeName($culture)
  {
    if (!$this->__isset('media_type_id'))
    {
      return;
    }

    if (0 == count(self::$lookups['mediaType']))
    {
      $criteria = new Criteria;
      $criteria->add(QubitTerm::TAXONOMY_ID, QubitTaxonomy::MEDIA_TYPE_ID);
      foreach (QubitTerm::get($criteria) as $item)
      {
        self::$lookups['mediaType'][$item->id] = $item;
      }
    }

    if (isset(self::$lookups['mediaType'][$this->__get('media_type_id')]))
    {
      return self::$lookups['mediaType'][$this->__get('media_type_id')]->getName(array(
        'culture' => $culture,
        'fallback' => true));
    }
  }

  public function getMimeType()
  {
    if (!$this->__isset('digital_object_id'))
    {
      return;
    }

    if (null !== $digitalObject = QubitDigitalObject::getById($this->__get('digital_object_id')))
    {
      return $digitalObject->getMimeType();
    }
  }

  public function getInheritReferenceCode()
  {
    if (null == $this->__get('identifier'))
    {
      return;
    }

    $refcode = '';
    $this->repository =$this->getRepository();

    if (isset($this->repository))
    {
      if (null != $cc = $this->repository->getCountryCode(array('culture' => $this->__get('culture'))))
      {
        $refcode .= $cc.' ';
      }

      if (isset($this->repository->identifier))
      {
        $refcode .= $this->repository->identifier.' ';
      }
    }

    $identifiers = array();
    $this->ancestors = $this->getAncestors();

    foreach (array_merge(is_array($this->ancestors) ? $this->ancestors : array(), array($this)) as $item)
    {
      if (isset($item->identifier))
      {
        $identifiers[] = $item->identifier;
      }
    }

    $refcode .= implode(sfConfig::get('app_separator_character', '-'), $identifiers);

    return $refcode;
  }

  /**
   * This is just for Binder supportig technologies. It's slow but affordable.
   */
  public function getInheritedTitle()
  {
    $title = array();
    if (!isset($this->ancestors))
    {
      $this->getAncestors();
    }

    foreach (array_merge(is_array($this->ancestors) ? $this->ancestors : array(), array($this)) as $item)
    {
      if ($item->id == QubitInformationObject::ROOT_ID)
      {
        continue;
      }

      $io = QubitInformationObject::getById($item->id);
      $title[] = $io->getTitle(array('cultureFallback' => true));
    }

    return implode(' » ', $title);
  }

  protected function loadEvents()
  {
    if (!isset($this->events))
    {
      $events = array();

      if (!isset(self::$statements['event']))
      {
        $sql  = 'SELECT
                    event.id,
                    event.start_date,
                    event.end_date,
                    event.actor_id,
                    event.type_id,
                    event.source_culture,
                    i18n.date,
                    i18n.culture';
        $sql .= ' FROM '.QubitEvent::TABLE_NAME.' event';
        $sql .= ' JOIN '.QubitEventI18n::TABLE_NAME.' i18n
                    ON event.id = i18n.id';
        $sql .= ' WHERE event.information_object_id = ?';

        self::$statements['event'] = self::$conn->prepare($sql);
      }

      self::$statements['event']->execute(array($this->__get('id')));

      foreach (self::$statements['event']->fetchAll() as $item)
      {
        if (!isset($events[$item['id']]))
        {
          $event = new stdClass;
          $event->id = $item['id'];
          $event->start_date = $item['start_date'];
          $event->end_date = $item['end_date'];
          $event->actor_id = $item['actor_id'];
          $event->type_id = $item['type_id'];
          $event->source_culture = $item['source_culture'];

          $events[$item['id']] = $event;
        }

        $events[$item['id']]->dates[$item['culture']] = $item['date'];
      }

      $this->events = $events;
    }

    return $this->events;
  }

  protected function getDates($field, $culture)
  {
    $dates = array();

    if (0 < count($this->events))
    {
      foreach ($this->events as $item)
      {
        switch($field)
        {
          case 'start_date':
          case 'end_date':
            if (isset($item->$field))
            {
              $date = new DateTime($item->$field);
              $dates[] = $date->format('Ymd');
            }

            break;

          case 'date':
            if (isset($item->dates[$culture]))
            {
              $dates[] = $item->dates[$culture];
            }
            else if (isset($item->start_date) || isset($item->end_date))
            {
              $dates[] = Qubit::renderDateStartEnd(null, $item->start_date, $item->end_date);
            }

            break;

          case 'array':

            $tmp = array();

            if (isset($item->date) && isset($item->dates[$culture]))
            {
              $tmp['date'] = $item->dates[$culture];
            }

            if (isset($item->start_date))
            {
              $tmp['startDate'] = arElasticSearchPluginUtil::convertDate($item->start_date);
              $tmp['startDateString'] = Qubit::renderDate($item->start_date);
            }

            if (isset($item->end_date))
            {
              $tmp['endDate'] = arElasticSearchPluginUtil::convertDate($item->end_date);
              $tmp['endDateString'] = Qubit::renderDate($item->end_date);
            }

            $tmp['typeId'] = $item->type_id;

            $dates[] = $tmp;

            break;
        }
      }
    }

    return $dates;
  }

  public function getActors($options = array())
  {
    $actors = array();

    if (!isset(self::$statements['actor']))
    {
      $sql  = 'SELECT
                  actor.id,
                  actor.entity_type_id,
                  slug.slug';
      $sql .= ' FROM '.QubitActor::TABLE_NAME.' actor';
      $sql .= ' JOIN '.QubitSlug::TABLE_NAME.' slug
                  ON actor.id = slug.object_id';
      $sql .= ' WHERE actor.id = ?';

      self::$statements['actor'] = self::$conn->prepare($sql);
    }

    if (0 < count($this->events))
    {
      foreach ($this->events as $item)
      {
        if (isset($item->actor_id))
        {
          // Filter by type
          if (isset($options['typeId']) && $options['typeId'] != $item->type_id)
          {
            continue;
          }

          self::$statements['actor']->execute(array($item->actor_id));

          if ($actor = self::$statements['actor']->fetch(PDO::FETCH_OBJ))
          {
            $actors[] = $actor;
          }
        }
      }
    }

    return $actors;
  }

  public function getNameAccessPoints()
  {
    $names = array();

    // Subject relations
    if (!isset(self::$statements['actorRelation']))
    {
      $sql  = 'SELECT actor.id';
      $sql .= ' FROM '.QubitActor::TABLE_NAME.' actor';
      $sql .= ' JOIN '.QubitRelation::TABLE_NAME.' relation
                  ON actor.id = relation.object_id';
      $sql .= ' WHERE relation.subject_id = :resourceId
                  AND relation.type_id = :typeId';

      self::$statements['actorRelation'] = self::$conn->prepare($sql);
    }

    self::$statements['actorRelation']->execute(array(
      ':resourceId' => $this->__get('id'),
      ':typeId' => QubitTerm::NAME_ACCESS_POINT_ID));

    foreach (self::$statements['actorRelation']->fetchAll(PDO::FETCH_OBJ) as $item)
    {
      $names[$item->id] = $item;
    }

    // Get actors linked via the "event" table (e.g. creators)
    foreach ($this->getActors() as $item)
    {
      $names[$item->id] = $item;
    }

    return $names;
  }

  /*
   * Get related terms and its ancestors
   */
  protected function getRelatedTerms($typeId)
  {
    $sql  = 'SELECT
                DISTINCT term.id,
                term.taxonomy_id,
                term.source_culture,
                slug.slug,
                i18n.name';
    $sql .= ' FROM '.QubitObjectTermRelation::TABLE_NAME.' otr';
    $sql .= ' JOIN '.QubitTerm::TABLE_NAME.' current
                ON otr.term_id = current.id';
    $sql .= ' JOIN '.QubitTerm::TABLE_NAME.' term
                ON term.lft <= current.lft AND term.rgt >= current.rgt';
    $sql .= ' JOIN '.QubitTermI18n::TABLE_NAME.' i18n
                ON term.id = i18n.id';
    $sql .= ' JOIN '.QubitSlug::TABLE_NAME.' slug
                ON term.id = slug.object_id';
    $sql .= ' WHERE otr.object_id = ?
               AND term.taxonomy_id = ?';

    self::$statements['relatedTerms'] = self::$conn->prepare($sql);
    self::$statements['relatedTerms']->execute(array($this->__get('id'), $typeId));

    return self::$statements['relatedTerms']->fetchAll(PDO::FETCH_OBJ);
  }

  /*
   * Get directly related terms
   */
  protected function getDirectlyRelatedTerms($typeId)
  {
    $sql  = 'SELECT
                DISTINCT current.id';
    $sql .= ' FROM '.QubitObjectTermRelation::TABLE_NAME.' otr';
    $sql .= ' JOIN '.QubitTerm::TABLE_NAME.' current
                ON otr.term_id = current.id';
    $sql .= ' WHERE otr.object_id = ?
                AND current.taxonomy_id = ?';

    self::$statements['relatedTerms'] = self::$conn->prepare($sql);
    self::$statements['relatedTerms']->execute(array($this->__get('id'), $typeId));

    return self::$statements['relatedTerms']->fetchAll(PDO::FETCH_OBJ);
  }

  protected function getLanguagesAndScripts()
  {
    // Find langs and scripts
    if (!isset(self::$statements['langAndScript']))
    {
      $sql  = 'SELECT
                  node.name,
                  i18n.value';
      $sql .= ' FROM '.QubitProperty::TABLE_NAME.' node';
      $sql .= ' JOIN '.QubitPropertyI18n::TABLE_NAME.' i18n
                  ON node.id = i18n.id';
      $sql .= ' WHERE node.source_culture = i18n.culture
                  AND node.object_id = ?
                  AND (node.name = ? OR node.name = ?)';

      self::$statements['langAndScript'] = self::$conn->prepare($sql);
    }

    self::$statements['langAndScript']->execute(array(
      $this->__get('id'),
      'language',
      'script'));

    // Add to arrays
    foreach (self::$statements['langAndScript']->fetchAll(PDO::FETCH_OBJ) as $item)
    {
      $codes = unserialize($item->value);

      if (0 < count($codes))
      {
        switch ($item->name)
        {
          case 'language':
            $this->languages = $codes;

            break;

          case 'script':
            $this->scripts = $codes;

            break;
        }
      }
    }

    return $this;
  }

  public function getNotes()
  {
    $notes = array();

    // Subject relations
    if (!isset(self::$statements['note']))
    {
      $sql  = 'SELECT
                  i18n.content';
      $sql .= ' FROM '.QubitNote::TABLE_NAME.' note';
      $sql .= ' WHERE note.object_id = ?';

      self::$statements['note'] = self::$conn->prepare($sql);
    }

    self::$statements['note']->execute(array(
      $this->__get('id')));

    foreach (self::$statements['note']->fetchAll(PDO::FETCH_OBJ) as $item)
    {
      if (0 < strlen($item->content))
      {
        $notes[] = $item->content;
      }
    }

    if (0 < count($notes))
    {
      return implode(' ', $notes);
    }
  }

  public function getNotesByType($typeId)
  {
    $sql  = 'SELECT
                id, source_culture';
    $sql .= ' FROM '.QubitNote::TABLE_NAME;
    $sql .= ' WHERE object_id = ?';
    $sql .= ' AND type_id = '.$typeId;

    self::$statements['noteByType'] = self::$conn->prepare($sql);
    self::$statements['noteByType']->execute(array($this->__get('id')));

    return self::$statements['noteByType']->fetchAll(PDO::FETCH_OBJ);
  }

  public function getThumbnailPath()
  {
    if (!$this->__isset('digital_object_id'))
    {
      return;
    }

    $criteria = new Criteria;
    $criteria->add(QubitDigitalObject::PARENT_ID, $this->__get('digital_object_id'));
    $criteria->add(QubitDigitalObject::USAGE_ID, QubitTerm::THUMBNAIL_ID);

    if (null !== $thumbnail = QubitDigitalObject::getOne($criteria))
    {
      return $thumbnail->getFullPath();
    }
  }

  public function getDigitalObjectMasterPath()
  {
    if (!$this->__isset('digital_object_id'))
    {
      return;
    }

    $criteria = new Criteria;
    $criteria->add(QubitDigitalObject::INFORMATION_OBJECT_ID, $this->__get('id'));
    $criteria->add(QubitDigitalObject::USAGE_ID, QubitTerm::MASTER_ID);

    if (null !== $master = QubitDigitalObject::getOne($criteria))
    {
      return $master->getFullPath();
    }
  }

  public function getMaterialTypeId()
  {
    if (!isset(self::$statements['materialType']))
    {
      $sql  = 'SELECT term.id';
      $sql .= ' FROM '.QubitObjectTermRelation::TABLE_NAME.' otr';
      $sql .= ' JOIN '.QubitTerm::TABLE_NAME.' term
                  ON otr.term_id = term.id';
      $sql .= ' WHERE object_id = ?';
      $sql .= ' AND term.taxonomy_id = '.QubitTaxonomy::MATERIAL_TYPE_ID;

      self::$statements['materialType'] = self::$conn->prepare($sql);
    }

    self::$statements['materialType']->execute(array(
      $this->__get('id')));

    return self::$statements['materialType']->fetchAll(PDO::FETCH_OBJ);
  }

  public function getStorageNames()
  {
    $names = array();

    // Subject relations
    if (!isset(self::$statements['storageName']))
    {
      $sql  = 'SELECT i18n.name';
      $sql .= ' FROM '.QubitRelation::TABLE_NAME.' rel';
      $sql .= ' WHERE rel.object_id = :resource_id';
      $sql .= '   AND rel.type_id = :type_id';

      self::$statements['storageName'] = self::$conn->prepare($sql);
    }

    self::$statements['storageName']->execute(array(
      ':resource_id' => $this->__get('id'),
      ':type_id' => QubitTerm::HAS_PHYSICAL_OBJECT_ID));

    foreach (self::$statements['storageName']->fetchAll(PDO::FETCH_OBJ) as $item)
    {
      if (0 < strlen($item->name))
      {
        $names[] = $item->name;
      }
    }

    if (0 < count($names))
    {
      return implode(' ', $names);
    }
  }

  public function getRights()
  {
    if (!isset(self::$statements['rights']))
    {
      $sql  = 'SELECT
                  restriction,
                  basis_id,
                  act_id,
                  rights_holder_id,
                  copyright_status_id';
      $sql .= ' FROM '.QubitRights::TABLE_NAME.' rights';
      $sql .= ' JOIN '.QubitRelation::TABLE_NAME.' rel
                  ON rights.id = rel.object_id';
      $sql .= ' WHERE rel.subject_id = ?';
      $sql .= ' AND rel.type_id = '.QubitTerm::RIGHT_ID;

      self::$statements['rights'] = self::$conn->prepare($sql);
    }

    self::$statements['rights']->execute(array(
      $this->__get('id')));

    return self::$statements['rights']->fetchAll(PDO::FETCH_CLASS);
  }

  /**
   * Get text transcript, if one exists
   */
  public function getTranscript()
  {
    if (!$this->__isset('digital_object_id'))
    {
      return;
    }

    if (!isset(self::$statements['transcript']))
    {
      $sql  = 'SELECT i18n.value
        FROM '.QubitProperty::TABLE_NAME.' property
        JOIN '.QubitPropertyI18n::TABLE_NAME.' i18n
          ON property.id = i18n.id
        WHERE property.name = "transcript"
          AND property.source_culture = i18n.culture
          AND property.object_id = ?';

      self::$statements['transcript'] = self::$conn->prepare($sql);
    }

    self::$statements['transcript']->execute(array($this->__get('digital_object_id')));

    return self::$statements['transcript']->fetchColumn();
  }

  protected function getAlternativeIdentifiers()
  {
    // Find langs and scripts
    if (!isset(self::$statements['alternativeIdentifiers']))
    {
      $sql  = 'SELECT
                  node.name,
                  i18n.value';
      $sql .= ' FROM '.QubitProperty::TABLE_NAME.' node';
      $sql .= ' JOIN '.QubitPropertyI18n::TABLE_NAME.' i18n
                  ON node.id = i18n.id';
      $sql .= ' WHERE node.source_culture = i18n.culture
                  AND node.object_id = ?
                  AND node.scope = ?';

      self::$statements['alternativeIdentifiers'] = self::$conn->prepare($sql);
    }

    self::$statements['alternativeIdentifiers']->execute(array(
      $this->__get('id'),
      'alternativeIdentifiers'));

    $alternativeIdentifiers = array();
    foreach (self::$statements['alternativeIdentifiers']->fetchAll() as $item)
    {
      $tmp = array();

      $tmp['label'] = $item['name'];
      $tmp['identifier'] = $item['value'];

      $alternativeIdentifiers[] = $tmp;
    }

    return $alternativeIdentifiers;
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

  protected function getAips()
  {
    $sql  = 'SELECT
                aip.id';
    $sql .= ' FROM '.QubitAip::TABLE_NAME.' aip';
    $sql .= ' JOIN '.QubitRelation::TABLE_NAME.' relation
                ON aip.id = relation.subject_id';
    $sql .= ' WHERE relation.object_id = ?
                AND relation.type_id = ?';

    self::$statements['aips'] = self::$conn->prepare($sql);
    self::$statements['aips']->execute(array($this->__get('id'), QubitTerm::AIP_RELATION_ID));

    return self::$statements['aips']->fetchAll(PDO::FETCH_OBJ);
  }

  protected function getAip($uuid)
  {
    $sql  = 'SELECT *';
    $sql .= ' FROM '.QubitAip::TABLE_NAME;
    $sql .= ' WHERE uuid = ?';

    self::$statements['aip'] = self::$conn->prepare($sql);
    self::$statements['aip']->execute(array($uuid));

    return self::$statements['aip']->fetch(PDO::FETCH_OBJ);
  }

  protected function getAipPartOfTitle($id)
  {
    $sql  = 'SELECT
                i18n.title';
    $sql .= ' FROM '.QubitInformationObjectI18n::TABLE_NAME.' i18n';
    $sql .= ' JOIN '.QubitInformationObject::TABLE_NAME.' inf';
    $sql .= ' ON inf.id = i18n.id';
    $sql .= ' JOIN '.QubitAip::TABLE_NAME.' aip';
    $sql .= ' ON aip.part_of = inf.id';
    $sql .= ' WHERE aip.id = ?';
    $sql .= ' AND i18n.culture = ?';

    self::$statements['aipPartOfTitle'] = self::$conn->prepare($sql);
    self::$statements['aipPartOfTitle']->execute(array($id, 'en'));

    return self::$statements['aipPartOfTitle']->fetchColumn();
  }

  protected function getAipAttachedTo($id)
  {
    $sql  = 'SELECT
                i18n.value';
    $sql .= ' FROM '.QubitProperty::TABLE_NAME.' node';
    $sql .= ' JOIN '.QubitPropertyI18n::TABLE_NAME.' i18n
                ON node.id = i18n.id';
    $sql .= ' WHERE node.source_culture = i18n.culture
                AND node.object_id = ?
                AND node.name = ?';

    self::$statements['aipAttachedTo'] = self::$conn->prepare($sql);
    self::$statements['aipAttachedTo']->execute(array($id, 'attachedTo'));

    return self::$statements['aipAttachedTo']->fetchColumn();
  }

  protected function getAipPartOfDepartments($id)
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
    self::$statements['parOfDepartments']->execute(array($id, sfConfig::get('app_drmc_taxonomy_departments_id'), 'en'));

    return self::$statements['parOfDepartments']->fetchAll(PDO::FETCH_OBJ);
  }

  protected function getArtworkId()
  {
    $sql  = 'SELECT id';
    $sql .= ' FROM '.QubitInformationObject::TABLE_NAME;
    $sql .= ' WHERE lft < ?
                AND rgt > ?
                AND level_of_description_id = ?';

    self::$statements['artwork'] = self::$conn->prepare($sql);
    self::$statements['artwork']->execute(array($this->__get('lft'), $this->__get('rgt'), sfConfig::get('app_drmc_lod_artwork_record_id')));

    return self::$statements['artwork']->fetchColumn();
  }

  protected function getArtworkDepartmentName($artworkId)
  {
    $sql  = 'SELECT
                i18n.name';
    $sql .= ' FROM '.QubitObjectTermRelation::TABLE_NAME.' otr';
    $sql .= ' JOIN '.QubitTerm::TABLE_NAME.' current
                ON otr.term_id = current.id';
    $sql .= ' JOIN '.QubitTermI18n::TABLE_NAME.' i18n
                ON otr.term_id = i18n.id';
    $sql .= ' WHERE otr.object_id = ?
                AND current.taxonomy_id = ?
                AND i18n.culture = ?';

    self::$statements['artworkDepartmentName'] = self::$conn->prepare($sql);
    self::$statements['artworkDepartmentName']->execute(array($artworkId, sfConfig::get('app_drmc_taxonomy_departments_id'), 'en'));

    return self::$statements['artworkDepartmentName']->fetchColumn();
  }

  protected function getArtworkArtist($artworkId)
  {
    $sql  = 'SELECT i18n.authorized_form_of_name';
    $sql .= ' FROM '.QubitActor::TABLE_NAME.' actor';
    $sql .= ' JOIN '.QubitEvent::TABLE_NAME.' event
                ON actor.id = event.actor_id';
    $sql .= ' JOIN '.QubitActorI18n::TABLE_NAME.' i18n
                ON actor.id = i18n.id';
    $sql .= ' WHERE event.information_object_id = ?
                AND event.type_id = ?
                AND i18n.culture = ?';

    self::$statements['artworkArtist'] = self::$conn->prepare($sql);
    self::$statements['artworkArtist']->execute(array($artworkId, QubitTerm::CREATION_ID, 'en'));

    return self::$statements['artworkArtist']->fetchColumn();
  }

  public function serialize()
  {
    $serialized = array();

    $serialized['id'] = $this->id;
    $serialized['slug'] = $this->slug;

    $serialized['identifier'] = $this->identifier;
    $serialized['inheritReferenceCode'] = $this->getInheritReferenceCode();
    $serialized['levelOfDescriptionId'] = $this->level_of_description_id;
    $serialized['publicationStatusId'] = $this->publication_status_id;

    // Alternative identifiers
    $alternativeIdentifiers = $this->getAlternativeIdentifiers();
    if (0 < count($alternativeIdentifiers))
    {
      $serialized['alternativeIdentifiers'] = $alternativeIdentifiers;
    }

    // NB: this will include the ROOT_ID
    foreach ($this->getAncestors() as $key => $ancestor)
    {
      $serialized['ancestors'][] = $ancestor->id;
    }

    $serialized['collectionRootId'] = (int)$this->getCollectionRoot()->id;

    $serialized['parentId'] = $this->ancestors[count($this->ancestors)-1]->id;

    // NB: this should be an ordered array
    foreach ($this->getChildren() as $child)
    {
      $serialized['children'][] = $child->id;
    }

    // Copyright status
    $statusId = null;
    foreach ($this->getRights() as $item)
    {
      if (isset($item->copyright_status_id))
      {
        $statusId = $item->copyright_status_id;
        break;
      }
    }
    if (null !== $statusId)
    {
      $serialized['copyrightStatusId'] = $statusId;
    }

    // Material type
    foreach ($this->getMaterialTypeId() as $item)
    {
      $serialized['materialTypeId'][] = $item->id;
    }

    // Media
    if ($this->media_type_id)
    {
      $serialized['digitalObject']['mediaTypeId'] = $this->media_type_id;
      $serialized['digitalObject']['usageId'] = $this->usage_id;
      $serialized['digitalObject']['mimeType'] = $this->mime_type;
      $serialized['digitalObject']['byteSize'] = $this->byte_size;
      $serialized['digitalObject']['checksum'] = $this->checksum;

      if (QubitTerm::EXTERNAL_URI_ID == $this->usage_id)
      {
        $serialized['digitalObject']['thumbnailPath'] = $this->path;
      }
      else
      {
        $serialized['digitalObject']['thumbnailPath'] = $this->getThumbnailPath();
      }

      $serialized['digitalObject']['masterPath'] = $this->getDigitalObjectMasterPath();

      $serialized['hasDigitalObject'] = true;
    }
    else
    {
      $serialized['hasDigitalObject'] = false;
    }

    // Dates
    foreach ($this->events as $event)
    {
      $serialized['dates'][] = arElasticSearchEvent::serialize($event);
    }

    // Transcript
    if (null !== $transcript = $this->getTranscript())
    {
      $serialized['transcript'] = $transcript;
    }

    // Repository
    if (null !== $repository = $this->getRepository())
    {
      $serialized['repository'] = arElasticSearchRepository::serialize($repository);
    }

    // Places
    foreach ($this->getRelatedTerms(QubitTaxonomy::PLACE_ID) as $item)
    {
      $node = new arElasticSearchTermPdo($item->id);
      $serialized['places'][] = $node->serialize();
    }

    foreach ($this->getDirectlyRelatedTerms(QubitTaxonomy::PLACE_ID) as $item)
    {
      $serialized['directPlaces'][] = $item->id;
    }

    // Subjects
    foreach ($this->getRelatedTerms(QubitTaxonomy::SUBJECT_ID) as $item)
    {
      $node = new arElasticSearchTermPdo($item->id);
      $serialized['subjects'][] = $node->serialize();
    }

    foreach ($this->getDirectlyRelatedTerms(QubitTaxonomy::SUBJECT_ID) as $item)
    {
      $serialized['directSubjects'][] = $item->id;
    }

    // Name access points
    foreach ($this->getNameAccessPoints() as $item)
    {
      $node = new arElasticSearchActorPdo($item->id);
      $serialized['names'][] = $node->serialize();
    }

    // Creators
    foreach ($this->getActors(array('typeId' => QubitTerm::CREATION_ID)) as $item)
    {
      $node = new arElasticSearchActorPdo($item->id);
      $serialized['creators'][] = $node->serialize();
    }

    // Aips related
    foreach ($this->getAips() as $item)
    {
      $node = new arElasticSearchAipPdo($item->id);
      $serialized['aips'][] = $node->serialize();
    }

    // PREMIS data
    if ($this->level_of_description_id === sfConfig::get('app_drmc_lod_digital_object_id')
      && null !== $premisData = arElasticSearchPluginUtil::getPremisData($this->id, self::$conn))
    {
      $serialized['metsData'] = $premisData;
    }

    // TMS object
    if ($this->level_of_description_id === sfConfig::get('app_drmc_lod_artwork_record_id'))
    {
      if (null !== $collectionDate = $this->getProperty('Dated'))
      {
        $serialized['tmsObject']['collectionDate'] = $collectionDate;
        $serialized['tmsObject']['collectionYear'] = arElasticSearchPluginUtil::parseYear($collectionDate);
      }

      if (null !== $dateCollected = $this->getProperty('AccessionISODate'))
      {
        $serialized['tmsObject']['dateCollected'] = arElasticSearchPluginUtil::convertDate($dateCollected);
        $serialized['tmsObject']['yearCollected'] = arElasticSearchPluginUtil::parseYear($dateCollected);
      }

      if (null !== $accessionNumber = $this->getProperty('ObjectNumber'))
      {
        $serialized['tmsObject']['accessionNumber'] = $accessionNumber;
      }

      if (null !== $thumbnail = $this->getProperty('Thumbnail'))
      {
        $serialized['tmsObject']['thumbnail'] = $thumbnail;
      }

      if (null !== $fullImage = $this->getProperty('FullImage'))
      {
        $serialized['tmsObject']['fullImage'] = $fullImage;
      }

      foreach ($this->getDirectlyRelatedTerms(sfConfig::get('app_drmc_taxonomy_classifications_id')) as $item)
      {
        $node = new arElasticSearchTermPdo($item->id);
        $serialized['tmsObject']['classification'][] = $node->serialize();
      }

      foreach ($this->getDirectlyRelatedTerms(sfConfig::get('app_drmc_taxonomy_departments_id')) as $item)
      {
        $node = new arElasticSearchTermPdo($item->id);
        $serialized['tmsObject']['department'][] = $node->serialize();
      }

      if (null !== $serialized['dates'][0]['startDate'])
      {
        $serialized['tmsObject']['dateCreated'][] = $serialized['dates'][0]['startDate'];
      }

      if (null !== $serialized['dates'][0]['endDate'])
      {
        $serialized['tmsObject']['dateCreated'][] = $serialized['dates'][0]['endDate'];
      }

      // TMS child components
      if (null !== $childComponents = $this->getProperty('childComponents'))
      {
        foreach (unserialize($childComponents) as $item)
        {
          try
          {
            $node = new arElasticSearchInformationObjectPdo($item);
            $serialized['tmsChildComponents'][] = $node->serialize();
          }
          catch (sfException $e) {}
        }
      }
    }

    // TMS component
    if (in_array($this->level_of_description_id, sfConfig::get('app_drmc_component_lod_ids')))
    {
      if (null !== $compCount = $this->getProperty('CompCount'))
      {
        $serialized['tmsComponent']['compCount'] = $compCount;
      }

      if (null !== $status = $this->getProperty('Status'))
      {
        $serialized['tmsComponent']['status'] = $status;
      }

      if (null !== $mediaFormat = $this->getProperty('Media Format'))
      {
        $serialized['tmsComponent']['mediaFormat'] = $mediaFormat;
      }

      if (null !== $componentNumber = $this->getProperty('ComponentNumber'))
      {
        $serialized['tmsComponent']['componentNumber'] = $componentNumber;
      }

      foreach ($this->getNotesByType(sfConfig::get('app_drmc_term_installcomments_id')) as $note)
      {
        $serialized['tmsComponent']['installComments'][] = arElasticSearchNote::serialize($note);
      }

      foreach ($this->getNotesByType(sfConfig::get('app_drmc_term_prepcomments_id')) as $note)
      {
        $serialized['tmsComponent']['prepComments'][] = arElasticSearchNote::serialize($note);
      }

      foreach ($this->getNotesByType(sfConfig::get('app_drmc_term_storagecomments_id')) as $note)
      {
        $serialized['tmsComponent']['storageComments'][] = arElasticSearchNote::serialize($note);
      }

      foreach ($this->getNotesByType(QubitTerm::GENERAL_NOTE_ID) as $note)
      {
        $serialized['tmsComponent']['textEntries'][] = arElasticSearchNote::serialize($note);
      }

      foreach ($this->getDirectlyRelatedTerms(sfConfig::get('app_drmc_taxonomy_component_types_id')) as $item)
      {
        $node = new arElasticSearchTermPdo($item->id);
        $serialized['tmsComponent']['type'][] = $node->serialize();
      }

      if (null !== $artworkId = $this->getArtworkId())
      {
        $serialized['tmsComponent']['artwork']['id'] = $artworkId;
        $serialized['tmsComponent']['artwork']['i18n'] = arElasticSearchModelBase::serializeI18ns($artworkId, array('QubitInformationObject'), array('fields' => array('title')));

        if (false !== $departmentName = $this->getArtworkDepartmentName($artworkId))
        {
          $serialized['tmsComponent']['artwork']['departmentName'] = $departmentName;
        }

        if (false !== $artist = $this->getArtworkArtist($artworkId))
        {
          $serialized['tmsComponent']['artwork']['artist'] = $artist;
        }
      }

      if (null !== $artworkThumbnail = $this->getProperty('artworkThumbnail'))
      {
        $serialized['tmsComponent']['artwork']['thumbnail'] = $artworkThumbnail;
      }
    }

    // Binder Tech Records
    if ($this->level_of_description_id === sfConfig::get('app_drmc_lod_supporting_technology_record_id'))
    {
      $serialized['inheritedTitle'] = $this->getInheritedTitle();
    }

    // From AIP
    if (null !== $aipUUID = $this->getProperty('aipUUID'))
    {
      if (false !== $aip = $this->getAip($aipUUID))
      {
        $serialized['aipUuid'] = $aip->uuid;
        $serialized['aipName'] = $aip->filename;

        if (false !== $partOfTitle = $this->getAipPartOfTitle($aip->id))
        {
          $serialized['aipPartOf'] = $partOfTitle;
        }

        if (false !== $attachedTo = $this->getAipAttachedTo($aip->id))
        {
          $serialized['aipAttachedTo'] = $attachedTo;
        }

        if (0 < count($aipPartOfDepartments = $this->getAipPartOfDepartments($aip->part_of)))
        {
          if (null !== $name = $aipPartOfDepartments[0]->name)
          {
            $serialized['aipPartOfDepartmentName'] = $name;
          }
        }
      }
    }

    // AIP file-specific
    if ($this->getProperty('original_relative_path_within_aip'))
    {
      $serialized['originalRelativePathWithinAip'] = $this->getProperty('original_relative_path_within_aip');
    }

    // Timestamps
    $serialized['createdAt'] = arElasticSearchPluginUtil::convertDate($this->created_at);
    $serialized['updatedAt'] = arElasticSearchPluginUtil::convertDate($this->updated_at);

    // Languages
    $serialized['sourceCulture'] = $this->source_culture;
    $serialized['i18n'] = arElasticSearchModelBase::serializeI18ns($this->id, array('QubitInformationObject'));

    return $serialized;
  }
}
