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
  * TMS v2
  *
  * @package    symfony
  * @subpackage tms
  */
class arFetchTmsV2 extends arFetchTmsBase
{
  protected
    // Map v2 => v1 field names used as property names
    $propertyNamesMapping = array(
      'accessionISODate' => 'AccessionISODate',
      'imageId' => 'ImageID',
      'objectNumber' => 'ObjectNumber',
      'compCount' => 'CompCount',
      'componentNumber' => 'ComponentNumber',
    );

  public function __construct()
  {
    parent::__construct();

    // Mapping from TMS status to level of descriptions
    $this->statusMapping = array(
      'Archival Master'         => sfConfig::get('app_drmc_lod_archival_master_id'),
      'Artist Supplied Master'  => sfConfig::get('app_drmc_lod_artist_supplied_master_id'),
      'Artist Verified Proof'   => sfConfig::get('app_drmc_lod_artist_verified_proof_id'),
      'Duplicating Copy'        => sfConfig::get('app_drmc_lod_duplicating_copy_id'),
      'Exhibition Format'       => sfConfig::get('app_drmc_lod_exhibition_format_id'),
      'Research Copy'           => sfConfig::get('app_drmc_lod_research_copy_id'),
      'Miscellaneous Other'     => sfConfig::get('app_drmc_lod_miscellaneous_id'),
      'Documentation'           => sfConfig::get('app_drmc_lod_documentation_id'),
      'Production Proof'        => sfConfig::get('app_drmc_lod_production_proof_id'),
      'Viewing Copy'            => sfConfig::get('app_drmc_lod_viewing_copy_id'),
      'Artist Supplied Package' => sfConfig::get('app_drmc_lod_artist_supplied_package_id'),
      'Production Materials'    => sfConfig::get('app_drmc_lod_production_materials_id'),
      'Auxiliary elements'      => sfConfig::get('app_drmc_lod_auxiliary_elements_id'),
    );

    $this->componentLevels = array_unique(array_values($this->statusMapping));
  }

  public function getLastModifiedCheckDate($tmsObjectId)
  {
    // No LastModifiedCheckDate in V2
    return null;
  }

  public function processArtwork($artwork)
  {
    $this->artworkThumbnail = null;

    // Init/clear component relations data
    $this->componentRelations = array();

    // Init/clear a mapping between IO ids and ComponentNumbers to avoid
    // hiting the database to get the IO id when the relations are processed
    $this->componentNumbersMapping = array();

    $this->getTmsObjectData($artwork);
    $componentsParent = $this->getOrCreateComponentsParent($artwork);
    $tmsComponentsIoIds = $this->getTmsComponentsData($artwork, $componentsParent);

    // Update relations between components
    $this->processComponentRelations($tmsComponentsIoIds);

    // Save info object components ids as property of the artwork
    // because they are not directly related but added as part of the artwork in ES
    QubitProperty::addUnique($artwork->id, 'childComponents', serialize($tmsComponentsIoIds), array('indexOnSave' => false));

    $this->updateRemainingEsDocs($artwork, $tmsComponentsIoIds);
  }

  private function getTmsObjectData($artwork)
  {
    $creation = array();

    // Request object from TMS API
    if (null !== $data = $this->getTmsData('/artworks/'.$artwork->identifier))
    {
      $data = $data['Object'];

      foreach ($data as $name => $value)
      {
        if (empty($value))
        {
          continue;
        }

        switch ($name)
        {
          // Info. object fields
          case 'dimensions':
            $artwork->physicalCharacteristics = $value;

            break;

          case 'medium':
            $artwork->extentAndMedium = $value;

            break;

          case 'objectId':
            // Already used the TMS id as identifier, which should match
            // the ObjectID. To avoid mismatches it's not added in here.
            // $artwork->identifier = $value;
            break;

          case 'title':
            $artwork->title = $value;

            break;

          // Properties
          case 'accessionISODate':
          case 'imageId':
          case 'objectNumber':
            $this->addOrUpdateProperty($this->propertyNamesMapping[$name], $value, $artwork);

            break;

          // Object/term relations
          case 'classification':
          case 'department':
            $this->addOrUpdateObjectTermRelation($name, $value, $artwork);

            break;

          // Creation event
          case 'dated':
            $creation['date'] = $value;

            break;

          // TODO: Allow multiple artist import
          case 'artistList':
            if (count($value) > 0)
            {
              if (isset($value[0]['displayName']))
              {
                $creation['actorName'] = $value[0]['displayName'];
              }

              if (isset($value[0]['displayDate']))
              {
                $creation['actorDate'] = $value[0]['displayDate'];
              }
            }

            break;

          // Digital object
          case 'fullImage':
            // Replace and combine backslashes with forwardslashes
            $path = preg_replace('/\/+/', '/', str_replace('\\', '/', $value));

            // Clear existing digital object
            if (null !== $digitalObject = $artwork->getDigitalObject())
            {
              $digitalObject->delete();
            }

            // Create a new one
            try
            {
              $filename = basename($path);
              $contents = file_get_contents($path);

              $digitalObject = new QubitDigitalObject;
              $digitalObject->informationObjectId = $artwork->id;
              $digitalObject->assets[] = new QubitAsset($filename, $contents);
              $digitalObject->usageId = QubitTerm::MASTER_ID;
              $digitalObject->indexOnSave = false;
              $digitalObject->save();

              // Add full path as property
              $this->addOrUpdateProperty('FullImage', $digitalObject->getFullPath(), $artwork);

              // If a thumbnail has been created, save as a property too and as a
              // global variable to use it when the components are created/updated.
              if (isset($digitalObject->thumbnail))
              {
                $this->artworkThumbnail = $digitalObject->thumbnail->getFullPath();
                $this->addOrUpdateProperty('Thumbnail', $this->artworkThumbnail, $artwork);
              }
            }
            catch (Exception $e)
            {
              $this->logger->info('arFetchTms - Error importing artwork image from "'.$value.'": '.$e->getMessage());
            }

            break;

          case 'terms':
            $content = array();

            foreach ($value as $term)
            {
              if (!empty($term['path']))
              {
                // Remove redundant part of breadcrumb
                $content[] = str_replace('Authorities > Attributes > Objects > ', '', $term['path']);
              }
            }

            $this->addOrUpdateNote(QubitTerm::GENERAL_NOTE_ID, implode($content, "; "), $artwork);

            break;
        }
      }
    }

    $artwork->save();

    if (count($creation))
    {
      $this->addOrUpdateCreationEvent($creation, $artwork);
    }
  }

  private function getTmsComponentsData($artwork, $componentsParent)
  {
    // Keep track of final components
    $tmsComponentsIoIds = array();

    // Request components from TMS API. This can be a big response as it returns all
    // components data. There is an individual endpoint to get a single component
    // per request, but the only way to know the component ids for that endpoint is
    // by hiting the all components endpoint, making the individual one unnecessary.
    if (null !== $data = $this->getTmsData('/artworks/'.$artwork->identifier.'/components'))
    {
      $data = $data['components'];

      foreach ($data as $componentData)
      {
        // Skip if the component data is missing the component id
        // as that's what is used to relate them with the IOs.
        if (!$componentData['componentId'])
        {
          continue;
        }

        // Clear relations and component number
        $relations = array();
        $componentNumber = null;

        // Look for existing component
        $criteria = new Criteria;
        $criteria->add(QubitInformationObject::IDENTIFIER, $componentData['componentId']);
        $criteria->add(QubitInformationObject::PARENT_ID, $componentsParent->id);

        if (null === $component = QubitInformationObject::getOne($criteria))
        {
          // Or create a new one
          $component = new QubitInformationObject;
          $component->parentId = $componentsParent->id;
          $component->identifier = $componentData['componentId'];
          $component->levelOfDescriptionId = sfConfig::get('app_drmc_lod_component_id');
          $component->setPublicationStatusByName('Published');
        }

        foreach ($componentData as $name => $value)
        {
          if (empty($value))
          {
            continue;
          }

          switch ($name)
          {
            // Info. object fields
            case 'componentId':
              // Already used the TMS id as identifier, which should match
              // the ComponentID. To avoid mismatches it's not added in here.
              // $component->identifier = $value;
              break;

            case 'componentName':
              // The name is an abbreviation of the status,
              // so we'll use it only if the status is empty.
              if (!isset($component->title))
              {
                $component->title = $value;
              }

              break;

            case 'componentStatus':
              $component->title = $value;
              $component->levelOfDescriptionId = $this->statusMapping[$value];

              break;

            case 'dimensions':
              $component->physicalCharacteristics = $value;

              break;

            case 'physDesc':
              $component->extentAndMedium = $value;

              break;

            // Properties
            case 'compCount':
              $this->addOrUpdateProperty($this->propertyNamesMapping[$name], $value, $component);

              break;

            case 'componentNumber':
              $this->addOrUpdateProperty($this->propertyNamesMapping[$name], $value, $component);
              $componentNumber = $value;

              break;

            case 'attributes':
              $content = array();

              foreach ($value as $attribute)
              {
                if (!empty($attribute['path']))
                {
                  // Remove redundant part of breadcrumb
                  $content[] = str_replace('Authorities > Attributes > Objects > Component attributes > ', '', $attribute['path']);
                }
              }

              if (count($content) > 0)
              {
                $this->addOrUpdateProperty('attributes', implode($content, "; "), $component, array('scope' => 'tms_attributes'));
              }

              break;

            // Object/term relation
            case 'componentType':
              $this->addOrUpdateObjectTermRelation('component_type', $value, $component);

              break;

            // Notes
            case 'installComments':
            case 'prepComments':
            case 'storageComments':
              $this->addOrUpdateNote(sfConfig::get('app_drmc_term_'.strtolower($name).'_id'), $value, $component);

              break;

            case 'textEntries':
              $content = array();

              foreach ($value as $textEntry)
              {
                if (!empty($textEntry['textType']) && !empty($textEntry['textEntryHtml']))
                {
                  $content[] = $textEntry['textType'].': '.$textEntry['textEntryHtml'];
                }
              }

              $this->addOrUpdateNote(QubitTerm::GENERAL_NOTE_ID, implode($content, "; "), $component);

              break;

            case 'relations':
              // Save relations for later
              foreach ($value as $relation)
              {
                if (!empty($relation['compNum']) && !empty($relation['type']))
                {
                  $relations[] = array(
                    'componentNumber' => trim($relation['compNum']),
                    'type' => trim($relation['type']),
                  );
                }
              }

              break;
          }
        }

        // Add thumbnail from artwork
        if (isset($this->artworkThumbnail))
        {
          $this->addOrUpdateProperty('artworkThumbnail', $this->artworkThumbnail, $component);
        }

        $component->save();
        $tmsComponentsIoIds[] = $component->id;

        if (count($relations))
        {
          $this->componentRelations[$component->id] = $relations;
        }

        if (isset($componentNumber))
        {
          $this->componentNumbersMapping[$componentNumber] = $component->id;
        }
      }
    }

    // Delete existing components not present in the current response
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::LFT, $artwork->lft, Criteria::GREATER_THAN);
    $criteria->add(QubitInformationObject::RGT, $artwork->rgt, Criteria::LESS_THAN);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, $this->componentLevels, Criteria::IN);
    $criteria->add(QubitInformationObject::ID, $tmsComponentsIoIds, Criteria::NOT_IN);

    foreach (QubitInformationObject::get($criteria) as $oldComponent)
    {
      // Move childs to parent of the component
      foreach ($oldComponent->getChildren() as $child)
      {
        $child->parentId = $oldComponent->parentId;
        $child->save();
      }

      // Delete (also deletes relations with AIPs)
      $oldComponent->delete();
    }

    return $tmsComponentsIoIds;
  }
}
