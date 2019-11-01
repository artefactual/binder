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
  * Abstract class to define the methods that should be implemented
  * in the different arFetchTms* classes. It also holds some common
  * properties and methods used by those classes.
  *
  * @package    symfony
  * @subpackage tms
  */
abstract class arFetchTmsBase
{
  protected
    $tmsBaseUrl,
    $logger,
    $searchInstance,
    $statusMapping,
    $componentLevels,
    $componentRelations,
    $componentNumbersMapping,
    $artworkThumbnail;

  public function __construct()
  {
    $this->tmsBaseUrl = sfConfig::get('app_drmc_tms_url');
    $this->logger = sfContext::getInstance()->getLogger();
    $this->searchInstance = QubitSearch::getInstance();
  }

  abstract public function getLastModifiedCheckDate($tmsObjectId);

  abstract public function processArtwork($artwork);

  protected function getTmsData($path)
  {
    $data = null;
    $url = $this->tmsBaseUrl.$path;

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FAILONERROR => true,
        CURLOPT_URL => $url));

    if (false === $resp = curl_exec($curl))
    {
      $this->logger->info('arFetchTms - Error getting Tombstone data: '.curl_error($curl));
      $this->logger->info('arFetchTms - URL: '.$url);
    }
    else
    {
      $data = json_decode($resp, true);
    }

    curl_close($curl);

    return $data;
  }

  protected function addOrUpdateProperty($name, $value, $io, $options = array())
  {
    if (isset($io->id) && null !== $property = QubitProperty::getOneByObjectIdAndName($io->id, $name))
    {
      if (isset($options['scope']))
      {
        $property->scope = $options['scope'];
      }

      $property->value = $value;
      $property->indexOnSave = false;
      $property->save();
    }
    else
    {
      $io->addProperty($name, $value, $options);
    }
  }

  protected function addOrUpdateObjectTermRelation($name, $value, $io)
  {
    $taxonomyId = sfConfig::get('app_drmc_taxonomy_'.strtolower($name).'s_id');
    $term = QubitFlatfileImport::createOrFetchTerm($taxonomyId, $value);

    // Check for existing term relation
    if (isset($io->id))
    {
      $criteria = new Criteria;
      $criteria->add(QubitObjectTermRelation::OBJECT_ID, $io->id);
      $criteria->addJoin(QubitObjectTermRelation::TERM_ID, QubitTerm::ID);
      $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomyId);

      $termRelation = QubitObjectTermRelation::getOne($criteria);
    }

    // Update
    if (isset($termRelation))
    {
      $termRelation->setTermId($term->id);
      $termRelation->indexOnSave = false;
      $termRelation->save();
    }
    // Or create new one
    else
    {
      $termRelation = new QubitObjectTermRelation;
      $termRelation->setTermId($term->id);

      $io->objectTermRelationsRelatedByobjectId[] = $termRelation;
    }
  }

  protected function addOrUpdateNote($typeId, $content, $io)
  {
    // Check for existing note
    if (isset($io->id))
    {
      $criteria = new Criteria;
      $criteria->add(QubitNote::OBJECT_ID, $io->id);
      $criteria->add(QubitNote::TYPE_ID, $typeId);

      $note = QubitNote::getOne($criteria);
    }

    // Update
    if (isset($note))
    {
      $note->content = $content;
      $note->indexOnSave = false;
      $note->save();
    }
    // Or create new one
    else
    {
      $note = new QubitNote;
      $note->content = $content;
      $note->typeId = $typeId;

      $io->notes[] = $note;
    }
  }

  protected function addOrUpdateCreationEvent($data, $io)
  {
    // Check for existing creation event
    if (isset($io->id))
    {
      $criteria = new Criteria;
      $criteria->add(QubitEvent::INFORMATION_OBJECT_ID, $io->id);
      $criteria->add(QubitEvent::TYPE_ID, QubitTerm::CREATION_ID);

      $creationEvent = QubitEvent::getOne($criteria);
    }

    // Or create new one
    if (!isset($creationEvent))
    {
      $creationEvent = new QubitEvent;
      $creationEvent->informationObjectId = $io->id;
      $creationEvent->typeId = QubitTerm::CREATION_ID;
    }

    $creationEvent->indexOnSave = false;

    // Add data
    qtSwordPlugin::addDataToCreationEvent($creationEvent, $data);
  }

  protected function updateRemainingEsDocs($artwork, $updatedIoIds)
  {
    // Update descendant IOs
    $sql = "SELECT id FROM information_object WHERE lft > ? AND rgt < ?;";
    $results = QubitPdo::fetchAll($sql, array($artwork->lft, $artwork->rgt));

    foreach ($results as $item)
    {
      // Skip those already updated
      if (!in_array($item->id, $updatedIoIds))
      {
        $node = new arElasticSearchInformationObjectPdo($item->id);
        $data = $node->serialize();

        $this->searchInstance->addDocument($data, 'QubitInformationObject');
      }
    }

    // Update artwork AIPs
    $sql = "SELECT id FROM aip WHERE part_of = ?;";
    $results = QubitPdo::fetchAll($sql, array($artwork->id));

    foreach ($results as $item)
    {
      $node = new arElasticSearchAipPdo($item->id);
      $data = $node->serialize();

      $this->searchInstance->addDocument($data, 'QubitAip');
    }

    // Update artwork
    $this->searchInstance->update($artwork);
  }

  protected function getOrCreateComponentsParent($artwork)
  {
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::PARENT_ID, $artwork->id);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_description_id'));

    if (null === $componentsParent = QubitInformationObject::getOne($criteria))
    {
      $componentsParent = new QubitInformationObject;
      $componentsParent->parentId = $artwork->id;
      $componentsParent->levelOfDescriptionId = sfConfig::get('app_drmc_lod_description_id');
      $componentsParent->setPublicationStatusByName('Published');
      $componentsParent->title = 'Components';
      $componentsParent->indexOnSave = false;
      $componentsParent->save();
    }

    return $componentsParent;
  }

  protected function processComponentRelations($ioIds)
  {
    $taxonomyId = sfConfig::get('app_drmc_taxonomy_associative_relationship_types_id');

    // Loop over the final components
    foreach ($ioIds as $ioId)
    {
      // Remove existing relations
      $criteria = new Criteria;
      $criteria->addJoin(QubitRelation::TYPE_ID, QubitTerm::ID);
      $criteria->add(QubitRelation::SUBJECT_ID, $ioId);
      $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomyId);

      foreach (QubitRelation::get($criteria) as $relation)
      {
        $relation->indexSubjectOnDelete = false;
        $relation->indexObjectOnDelete = false;
        $relation->delete();
      }

      // Ignore components without relations data
      if (!isset($this->componentRelations[$ioId]))
      {
        continue;
      }

      foreach ($this->componentRelations[$ioId] as $relationData)
      {
        // Ignore relations without type, relations to external resources
        // and relations to components outside this artwork
        if (!isset($relationData['type']) || !isset($relationData['componentNumber']) ||
            !isset($this->componentNumbersMapping[$relationData['componentNumber']]))
        {
          continue;
        }

        $relatedComponentId = $this->componentNumbersMapping[$relationData['componentNumber']];

        // Get or create relation type term
        $termName = trim($relationData['type']);
        $relationType = QubitFlatfileImport::createOrFetchTerm($taxonomyId, $termName);

        // Create relation
        $relation = new QubitRelation;
        $relation->type = $relationType;
        $relation->objectId = $relatedComponentId;
        $relation->subjectId = $ioId;
        $relation->indexOnSave = false;
        $relation->save();
      }
    }
  }
}
