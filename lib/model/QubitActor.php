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
 * @package    AccesstoMemory
 * @subpackage actor
 * @author     Peter Van Garderen <peter@artefactual.com>
 * @author     David Juhasz <david@artefactual.com>
 */
class QubitActor extends BaseActor
{
  const
    ROOT_ID = 3;

  public function __toString()
  {
    $string = $this->authorizedFormOfName;
    if (!isset($string))
    {
      $string = $this->getAuthorizedFormOfName(array('sourceCulture' => true));
    }

    return (string) $string;
  }

  public function __get($name)
  {
    $args = func_get_args();

    $options = array();
    if (1 < count($args))
    {
      $options = $args[1];
    }

    switch ($name)
    {
      case 'language':
      case 'script':

        if (!isset($this->values[$name]))
        {
          $criteria = new Criteria;
          $this->addPropertysCriteria($criteria);
          $criteria->add(QubitProperty::NAME, $name);

          if (1 == count($query = QubitProperty::get($criteria)))
          {
            $this->values[$name] = $query[0];
          }
        }

        if (isset($this->values[$name]) && null !== $value = unserialize($this->values[$name]->__get('value', $options + array('sourceCulture' => true))))
        {
          return $value;
        }

        return array();
    }

    return call_user_func_array(array($this, 'BaseActor::__get'), $args);
  }

  public function __set($name, $value)
  {
    $args = func_get_args();

    $options = array();
    if (2 < count($args))
    {
      $options = $args[2];
    }

    switch ($name)
    {
      case 'language':
      case 'script':

        if (!isset($this->values[$name]))
        {
          $criteria = new Criteria;
          $this->addPropertysCriteria($criteria);
          $criteria->add(QubitProperty::NAME, $name);

          if (1 == count($query = QubitProperty::get($criteria)))
          {
            $this->values[$name] = $query[0];
          }
          else
          {
            $this->values[$name] = new QubitProperty;
            $this->values[$name]->name = $name;
            $this->propertys[] = $this->values[$name];
          }
        }

        $this->values[$name]->__set('value', serialize($value), $options + array('sourceCulture' => true));

        return $this;
    }

    return call_user_func_array(array($this, 'BaseActor::__set'), $args);
  }

  protected function insert($connection = null)
  {
    if (!isset($this->slug))
    {
      $this->slug = QubitSlug::slugify($this->__get('authorizedFormOfName', array('sourceCulture' => true)));
    }

    return parent::insert($connection);
  }

  public function save($connection = null)
  {
    if (QubitActor::ROOT_ID != $this->id && !isset($this->parentId) && 'QubitActor' == $this->className)
    {
      $this->parentId = QubitActor::ROOT_ID;
    }
    else if (QubitRepository::ROOT_ID != $this->id && !isset($this->parentId) && 'QubitRepository' == $this->className)
    {
      $this->parentId = QubitRepository::ROOT_ID;
    }

    parent::save($connection);

    // Save related event objects
    foreach ($this->events as $event)
    {
      // Update search index for related info object
      $event->indexOnSave = true;
      $event->actor = $this;

      try
      {
        $event->save();
      }
      catch (PropelException $e)
      {
      }
    }

    // Save related contact information objects
    foreach ($this->contactInformations as $item)
    {
      $item->actor = $this;

      try
      {
        $item->save();
      }
      catch (PropelException $e)
      {
      }
    }

    // Repositories are updated in the save function for QubitRepository class
    // in order to get the i18n values updated in the search index
    if ('QubitActor' == $this->className)
    {
      QubitSearch::getInstance()->update($this);
    }

    return $this;
  }

  public function delete($connection = null)
  {
    if (!($this instanceOf QubitRightsHolder || $this instanceOf QubitDonor))
    {
      QubitSearch::getInstance()->delete($this);
    }

    return parent::delete($connection);
  }

  public static function getRoot()
  {
    return self::getById(self::ROOT_ID);
  }

  public static function getAllExceptUsers($options = array())
  {
    //returns all Actor objects except those that are
    //also an instance of the User class
    $criteria = new Criteria;
    $criteria->add(QubitObject::CLASS_NAME, 'QubitActor');

    // sort by name
    $criteria->addAscendingOrderByColumn('authorized_form_of_name');

    // Do fallback
    $criteria = QubitCultureFallback::addFallbackCriteria($criteria, 'QubitActor', $options);

    return QubitActor::get($criteria);
  }

  /**
   * Return an options_for_select array
   *
   * @param mixed $default current selected value for select list
   * @param array $options optional parameters
   * @return array options_for_select compatible array
   */
  public static function getOptionsForSelectList($default, $options = array())
  {
    $actors = self::getAllExceptUsers($options);

    foreach ($actors as $actor)
    {
      // Don't display actors with no name
      if ($name = $actor->getAuthorizedFormOfName($options))
      {
        $selectOptions[$actor->id] = $name;
      }
    }

    return options_for_select($selectOptions, $default, $options);
  }

  /**
   * Append criteria to get only Actor objects that are NOT
   * a users or repository.
   *
   * @param Criteria $criteria current search criteria
   * @return Criteria modified criteria object
   */
  public static function addGetOnlyActorsCriteria($criteria)
  {
    $criteria->addJoin(QubitActor::ID, QubitObject::ID);
    $criteria->add(QubitObject::CLASS_NAME, 'QubitActor');

    return $criteria;
  }

  /**
   * Returns only Actor objects, excluding those
   * that are an instance of the User or Repository class
   *
   * @return QubitQuery array of QubitActor objects
   */
  public static function getOnlyActors($criteria=null, $options=array())
  {
    if (is_null($criteria))
    {
      $criteria = new Criteria;
    }

    $criteria = QubitActor::addGetOnlyActorsCriteria($criteria);

    return self::get($criteria);
  }

  public static function getAllNames()
  {
    $actors = self::getOnlyActors();
    $allActorNames = array();
    foreach ($actors as $actor)
    {
      $actorId = $actor->id;
      $allActorNames[] = array('actorId' => $actorId, 'nameId' => null, 'name' => $actor->getAuthorizedFormOfName());
      $actorNames = array();
      $actorNames = $actor->getOtherNames();
      foreach ($actorNames as $name)
      {
        $allActorNames[] = array('actorId' => $actorId, 'nameId' => $name->id, 'name' => $name.' ('.$name->getType().')');
      }
    }

    return $allActorNames;
  }

  /**
   * Add a related property to this actor.
   *
   * @param string $name  name of property
   * @param string $value value of property
   * @param string $options array of optional parameters
   * @return QubitActor this object
   */
  public function addProperty($name, $value, $options = array())
  {
    $property = QubitProperty::addUnique($this->id, $name, $value, $options);

    return $this;
  }

  public function getProperties($name = null, $scope = null)
  {
    $criteria = new Criteria;
    $criteria->add(QubitProperty::OBJECT_ID, $this->id);
    if ($name)
    {
      $criteria->add(QubitProperty::NAME, $name);
    }
    if ($scope)
    {
      $criteria->add(QubitProperty::SCOPE, $scope);
    }

    return QubitProperty::get($criteria);
  }

  public function getActorNotes()
  {
    $criteria = new Criteria;
    $criteria->addJoin(QubitNote::TYPE_ID, QubitTerm::ID);
    $criteria->add(QubitNote::OBJECT_ID, $this->id);
    $criteria->add(QubitNote::SCOPE, 'QubitActor');
    QubitNote::addOrderByPreorder($criteria);

    return QubitNote::get($criteria);
  }

  public function getContactInformation()
  {
    $criteria = new Criteria;
    $criteria->add(QubitContactInformation::ACTOR_ID, $this->id);
    $criteria->addDescendingOrderByColumn(QubitContactInformation::PRIMARY_CONTACT);
    $contactInformation = QubitContactInformation::get($criteria);

    return $contactInformation;
  }

  public function getPrimaryContact()
  {
    $criteria = new Criteria;
    $criteria->add(QubitContactInformation::ACTOR_ID, $this->id);
    $criteria->add(QubitContactInformation::PRIMARY_CONTACT, true);
    $primaryContact = QubitContactInformation::getOne($criteria);

    if ($primaryContact)
    {
      return $primaryContact;
    }
    else
    {
      $criteria = new Criteria;
      $criteria->add(QubitContactInformation::ACTOR_ID, $this->id);

      return QubitContactInformation::getOne($criteria);
    }
  }

  protected $SubjectHitCount = null;

  public function setSubjectHitCount($count)
  {
    $this->SubjectHitCount = $count;
  }

  public function getSubjectHitCount()
  {
    return $this->SubjectHitCount;
  }

  /**
   * Save new link to a term.
   *
   * @param integer $termId QubitTerm primary key
   * @param string $relationNote DEPRECATED
   */
  public function setTermRelation($termId, $relationNote = null)
  {
    $newTermRelation = new QubitObjectTermRelation;
    $newTermRelation->setTermId($termId);

    //TODO: move to QubitNote
    //  $newTermRelation->setRelationNote($relationNote);
    $newTermRelation->setObjectId($this->id);
    $newTermRelation->save();
  }

  /**
   * Get many-to-many links to QubitTerm objects
   *
   * @param mixed $taxonomyId  Limit results by taxonomy type
   * @return QubitQuery collection of QubitObjectTermRelation objects
   */
  public function getTermRelations($taxonomyId = 'all')
  {
    $criteria = new Criteria;
    $criteria->add(QubitObjectTermRelation::OBJECT_ID, $this->id);

    if ($taxonomyId != 'all')
    {
      $criteria->addJoin(QubitObjectTermRelation::TERM_ID, QubitTERM::ID);
      $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomyId);
    }

    return QubitObjectTermRelation::get($criteria);
  }

  public function getDatesOfChanges()
  {
    //TO DO

    return null;
  }

  /**
   * Get actor-to-actor relations linked to this actor
   *
   * @return QubitQuery collection of QubitRelation objects
   */
  public function getActorRelations()
  {
    $criteria = new Criteria;
    $criteria->addJoin(QubitRelation::TYPE_ID, QubitTerm::ID);
    $criteria->add($criteria->getNewCriterion(QubitRelation::OBJECT_ID, $this->id)
      ->addOr($criteria->getNewCriterion(QubitRelation::SUBJECT_ID, $this->id))
      ->addAnd($criteria->getNewCriterion(QubitTerm::TAXONOMY_ID, QubitTaxonomy::ACTOR_RELATION_TYPE_ID)));

    $criteria->addAscendingOrderByColumn(QubitRelation::TYPE_ID);
    $criteria->addDescendingOrderByColumn(QubitRelation::START_DATE);

    return QubitRelation::get($criteria);
  }

  /**
   * Search for an actor by the AUTHORIZED_FORM_OF_NAME i18n column. Optionally
   * limit search to a specific culture.
   *
   * @param string $name search string
   * @param array $options optional parameters
   * @return QubitActor found actor
   */
  public static function getByAuthorizedFormOfName($name, $options = array())
  {
    $criteria = new Criteria();
    $criteria->addJoin(QubitActor::ID, QubitActorI18n::ID);
    $criteria->add(QubitActorI18n::AUTHORIZED_FORM_OF_NAME, $name);

    if (isset($options['culture']))
    {
      $criteria->addAnd(QubitActorI18n::CULTURE, $options['culture']);
    }

    return QubitActor::getOne($criteria, $options);
  }

  public function getLabel()
  {
    $label = null;
    if (null !== $this->descriptionIdentifier)
    {
      $label .= $this->descriptionIdentifier;
    }
    if (null !== $value = $this->getAuthorizedFormOfName(array('cultureFallback' => true)))
    {
      $label = (0 < strlen($label)) ? $label.' - '.$value : $value;
    }

    return $label;
  }

  public function getResourceRelations()
  {
    $criteria = new Criteria;
    $criteria->addJoin(QubitInformationObject::ID, QubitEvent::INFORMATION_OBJECT_ID);
    $criteria->addGroupByColumn(QubitInformationObject::ID);
    $criteria->add(QubitEvent::ACTOR_ID, $this->id);

    return QubitInformationObject::get($criteria);
  }

  public static function getByNameAndRepositoryId($name, $repositoryId = null)
  {
    // Get first actor with the given name and related
    // to descriptions in the same repository.
    // If the repository id is not set check relations
    // with descriptions without repository
    if (isset($repositoryId))
    {
      $repositoryCondition = 'WHERE io.repository_id=?';
      $params = array($name, $repositoryId, $repositoryId, $repositoryId);
    }
    else
    {
      $repositoryCondition = 'WHERE io.repository_id IS NULL';
      $params = array($name);
    }

    $sql  = 'SELECT act.id FROM actor act ';
    $sql .= 'INNER JOIN actor_i18n i18n ON act.id=i18n.id ';
    $sql .= 'WHERE i18n.authorized_form_of_name=? ';
    $sql .= 'AND act.id IN (';
    $sql .= 'SELECT r.object_id AS id FROM relation r ';
    $sql .= 'LEFT JOIN information_object io ON r.subject_id=io.id ';
    $sql .= $repositoryCondition;
    $sql .= ' UNION SELECT r.subject_id AS id FROM relation r ';
    $sql .= 'LEFT JOIN information_object io ON r.object_id=io.id ';
    $sql .= $repositoryCondition;
    $sql .= ' UNION SELECT e.actor_id AS id FROM event e ';
    $sql .= 'LEFT JOIN information_object io ON e.information_object_id=io.id ';
    $sql .= $repositoryCondition;
    $sql .= ')';

    if (false !== $actorId = QubitPdo::fetchColumn($sql, $params))
    {
      return self::getById($actorId);
    }
  }
}
