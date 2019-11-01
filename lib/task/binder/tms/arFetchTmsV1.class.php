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
  * TMS v1
  *
  * @package    symfony
  * @subpackage tms
  */
class arFetchTmsV1 extends arFetchTmsBase
{
  public function __construct()
  {
    parent::__construct();

    // Mapping from TMS status to level of descriptions
    $this->statusMapping = array(
      'Archival'               => sfConfig::get('app_drmc_lod_archival_master_id'),
      'Archival submaster'     => sfConfig::get('app_drmc_lod_archival_master_id'),
      'Artist master'          => sfConfig::get('app_drmc_lod_artist_supplied_master_id'),
      'Artist proof'           => sfConfig::get('app_drmc_lod_artist_verified_proof_id'),
      'Duplication master'     => sfConfig::get('app_drmc_lod_component_id'),
      'Exhibition copy'        => sfConfig::get('app_drmc_lod_exhibition_format_id'),
      'Miscellaneous other'    => sfConfig::get('app_drmc_lod_miscellaneous_id'),
      'Repository File Source' => sfConfig::get('app_drmc_lod_component_id'),
      'Research copy'          => sfConfig::get('app_drmc_lod_component_id')
    );

    $this->componentLevels = array_unique(array_values($this->statusMapping));
  }

  public function getLastModifiedCheckDate($tmsObjectId)
  {
    // Request object from TMS API
    if (null !== $data = $this->getTmsData('/GetTombstoneDataRest/ObjectID/'.$tmsObjectId))
    {
      $data = $data['GetTombstoneDataRestIdResult'];

      if (isset($data['LastModifiedCheckDate']))
      {
        return $data['LastModifiedCheckDate'];
      }
    }

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

    // Keep track of final components
    $tmsComponentsIoIds = array();

    // Fetch TMS data for artwork and get/create components parent
    $tmsComponentsIds = $this->getTmsObjectData($artwork);
    $componentsParent = $this->getOrCreateComponentsParent($artwork);

    // Update or delete exiting artwork components
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::LFT, $artwork->lft, Criteria::GREATER_THAN);
    $criteria->add(QubitInformationObject::RGT, $artwork->rgt, Criteria::LESS_THAN);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, $this->componentLevels, Criteria::IN);

    foreach (QubitInformationObject::get($criteria) as $component)
    {
      if (isset($component->identifier) && false !== $key = array_search($component->identifier, $tmsComponentsIds))
      {
        // Update TMS Component data
        $tmsComponentsIoIds[] = $this->getTmsComponentData($component);

        // Remove from array to avoid creation later
        unset($tmsComponentsIds[$key]);
      }
      else
      {
        // Move childs to parent of the component
        foreach ($component->getChildren() as $child)
        {
          $child->parentId = $component->parentId;
          $child->save();
        }

        // Delete (also deletes relations with AIPs and other components)
        $component->delete();
      }
    }

    // Create new components with the remaining TMS ids in the array
    foreach ($tmsComponentsIds as $tmsId)
    {
      $component = new QubitInformationObject;
      $component->parentId = $componentsParent->id;
      $component->identifier = $tmsId;
      $component->levelOfDescriptionId = sfConfig::get('app_drmc_lod_component_id');
      $component->setPublicationStatusByName('Published');

      // Update TMS Component data
      $tmsComponentsIoIds[] = $this->getTmsComponentData($component);
    }

    // Update relations between components
    $this->processComponentRelations($tmsComponentsIoIds);

    // Save info object components ids as property of the artwork
    // because they are not directly related but added as part of the artwork in ES
    QubitProperty::addUnique($artwork->id, 'childComponents', serialize($tmsComponentsIoIds), array('indexOnSave' => false));

    $this->updateRemainingEsDocs($artwork, $tmsComponentsIoIds);
  }

  private function getTmsObjectData($artwork)
  {
    $tmsComponentsIds = $creation = array();

    // Request object from TMS API
    if (null !== $data = $this->getTmsData('/GetTombstoneDataRest/ObjectID/'.$artwork->identifier))
    {
      $data = $data['GetTombstoneDataRestIdResult'];

      foreach ($data as $name => $value)
      {
        if (!isset($value) || 0 == strlen($value))
        {
          continue;
        }

        switch ($name)
        {
          // Info. object fields
          case 'Dimensions':
            $artwork->physicalCharacteristics = $value;

            break;

          case 'Medium':
            $artwork->extentAndMedium = $value;

            break;

          case 'ObjectID':
            // Already used the TMS id as identifier, which should match
            // the ObjectID. To avoid mismatches it's not added in here.
            // $artwork->identifier = $value;
            break;

          case 'Title':
            $artwork->title = $value;

            break;

          // Properties
          case 'AccessionISODate':
          case 'ClassificationID':
          case 'ConstituentID':
          case 'DepartmentID':
          case 'LastModifiedCheckDate':
          case 'ImageID':
          case 'ObjectNumber':
          case 'ObjectStatusID':
          case 'SortNumber':
            $this->addOrUpdateProperty($name, $value, $artwork);

            break;

          // Object/term relations
          case 'Classification':
          case 'Department':
            $this->addOrUpdateObjectTermRelation($name, $value, $artwork);

            break;

          // Creation event
          case 'Dated':
            $creation['date'] = $value;

            break;

          case 'DisplayName':
            $creation['actorName'] = $value;

            break;

          case 'DisplayDate':
            $creation['actorDate'] = $value;

            break;

          // Digital object
          case 'FullImage':
            // Encode filename in the URL
            $filename = basename(parse_url($value, PHP_URL_PATH));
            $uri = str_replace($filename, rawurlencode($filename), $value);

            // Clear existing digital object
            if (null !== $digitalObject = $artwork->getDigitalObject())
            {
              $digitalObject->delete();
            }

            // Create a new one
            $digitalObject = new QubitDigitalObject;
            $digitalObject->informationObjectId = $artwork->id;
            $digitalObject->importFromURI($uri);
            $digitalObject->indexOnSave = false;
            $digitalObject->save();

            // Add property
            $this->addOrUpdateProperty($name, $uri, $artwork);

            break;

          case 'Thumbnail':
            $this->artworkThumbnail = $value;
            $this->addOrUpdateProperty($name, $value, $artwork);

            break;

          // Child components
          case 'Components':
            foreach (json_decode($value, true) as $item)
            {
              $tmsComponentsIds[] = $item['ComponentID'];
            }

            break;

          // Log error
          case 'ErrorMsg':
            $this->logger->info('arFetchTms - ErrorMsg: '.$value);

            break;

          // Nothing yet
          case 'AlphaSort':
          case 'CreditLine':
          case 'FirstName':
          case 'LastName':
          case 'Prints':

            break;
        }
      }
    }

    $artwork->save();

    if (count($creation))
    {
      $this->addOrUpdateCreationEvent($creation, $artwork);
    }

    return $tmsComponentsIds;
  }

  private function getTmsComponentData($component)
  {
    // Request component from TMS API
    if (null !== $data = $this->getTmsData('/GetComponentDetails/Component/'.$component->identifier))
    {
      $data = $data['GetComponentDetailsResult'];

      // Attributes can have multiple items with the same label.
      // To avoid updating only the first property with that label
      // all the tms_attributes properties are deleted first
      foreach ($component->getProperties(null, 'tms_attributes') as $property)
      {
        $property->indexOnSave = false;
        $property->delete();
      }

      $relations = array();
      $componentNumber = null;

      foreach ($data as $name => $value)
      {
        if (empty($value))
        {
          continue;
        }

        switch ($name)
        {
          case 'Attributes':
            foreach (json_decode($value, true) as $item)
            {
              if (!empty($item['Relation']) && !empty($item['Remarks']))
              {
                // Save relation attributes for later
                $relations[] = array(
                  'componentNumber' => trim($item['Remarks']),
                  'type' => trim($item['Relation']),
                );
              }

              // Level of description from status attribute
              if (!empty($item['Status']) && isset($this->statusMapping[$item['Status']]))
              {
                $component->levelOfDescriptionId = $this->statusMapping[$item['Status']];
              }

              // Add property for each attribute
              $count = 0;
              $propertyName = $propertyValue = null;

              foreach ($item as $key => $value)
              {
                if (empty($key) || empty($value))
                {
                  continue;
                }

                // Get property name from first key
                if ($count == 0)
                {
                  $propertyName = $key;
                  $propertyValue = $value;
                }
                else
                {
                  $propertyValue .= '. '.$key;
                  $propertyValue .= ': '.$value;
                }

                $count ++;
              }

              if (isset($propertyName) && isset($propertyValue))
              {
                $this->addOrUpdateProperty($propertyName, $propertyValue, $component, array('scope' => 'tms_attributes'));
              }
            }

            break;

          // Info. object fields
          case 'ComponentID':
            // Already used the TMS id as identifier, which should match
            // the ComponentID. To avoid mismatches it's not added in here.
            // $component->identifier = $value;
            break;

          case 'ComponentName':
            $component->title = $value;

            break;

          case 'Dimensions':
            $component->physicalCharacteristics = $value;

            break;

          case 'PhysDesc':
            $component->extentAndMedium = $value;

            break;

          // Properties
          case 'CompCount':
            $this->addOrUpdateProperty($name, $value, $component);

            break;

          case 'ComponentNumber':
            $this->addOrUpdateProperty($name, $value, $component);
            $componentNumber = $value;

            break;

          // Object/term relation
          case 'ComponentType':
            $this->addOrUpdateObjectTermRelation('component_type', $value, $component);

            break;

          // Notes
          case 'InstallComments':
          case 'PrepComments':
          case 'StorageComments':
            $this->addOrUpdateNote(sfConfig::get('app_drmc_term_'.strtolower($name).'_id'), $value, $component);

            break;

          case 'TextEntries':
            $content = array();

            foreach (json_decode($value, true) as $textEntry)
            {
              $row = '';
              foreach ($textEntry as $field => $value)
              {
                if ($field == 'TextDate' && !empty($value))
                {
                  $row .= ', Date: '.$value;
                }
                else if ($field == 'TextAuthor' && !empty($value))
                {
                  $row .= ', Author: '.$value;
                }
                else if (!empty($field) && !empty($value))
                {
                  $row .= $field.': '.$value;
                }
              }

              $content[] = $row;
            }

            $this->addOrUpdateNote(QubitTerm::GENERAL_NOTE_ID, implode($content, "\n"), $component);

            break;

          // Log error
          case 'ErrorMsg':
            $this->logger->info('arFetchTms - ErrorMsg: '.$value);

            break;

          // Nothing yet
          case 'ObjectID':

            break;
        }
      }
    }

    // Add thumbnail from artwork
    if (isset($this->artworkThumbnail))
    {
      $this->addOrUpdateProperty('artworkThumbnail', $this->artworkThumbnail, $component);
    }

    $component->save();

    if (count($relations))
    {
      $this->componentRelations[$component->id] = $relations;
    }

    if (isset($componentNumber))
    {
      $this->componentNumbersMapping[$componentNumber] = $component->id;
    }

    return $component->id;
  }
}
