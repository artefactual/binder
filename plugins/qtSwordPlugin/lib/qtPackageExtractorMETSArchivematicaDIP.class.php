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

class qtPackageExtractorMETSArchivematicaDIP extends qtPackageExtractorBase
{
  protected function process()
  {
    ProjectConfiguration::getActive()->loadHelpers('Qubit');

    // AIP UUID
    $this->aipUUID = $this->getUUID($this->filename);

    if (null !== QubitAip::getByUuid($this->aipUUID))
    {
      throw new sfException('There is already a AIP with the given UUID');
    }

    // Find METS file
    if ($handle = opendir($this->filename))
    {
      while (false !== $entry = readdir($handle))
      {
        if (0 < preg_match('/^METS\..*\.xml$/', $entry))
        {
          $path = $this->filename.DIRECTORY_SEPARATOR.$entry;

          sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP - Opening '.$path);

          $this->document = new SimpleXMLElement(@file_get_contents($path));

          break;
        }
      }

      closedir($handle);
    }
    else
    {
      throw new sfException('METS XML file was not found.');
    }

    if (!isset($this->document))
    {
      throw new sfException('METS document could not be opened.');
    }

    // Initialice METS parser, used in addDigitalObjects to add
    // the required data from the METS file to the digital objects
    $this->metsParser = new QubitMetsParser($this->document);

    // Stop if there isn't a proper structMap
    if (null === $structMap = $this->metsParser->getStructMap())
    {
      throw new sfException('A proper structMap could not be found in the METS file.');
    }

    // Load mappings
    $this->mappings = $this->metsParser->getDipUploadMappings($structMap);

    // Check Archivematica Binder prefix
    $drmcPrefix = substr($this->resource, 0, 3);
    $drmcSuffix = substr($this->resource, 3);
    switch ($drmcPrefix)
    {
      case 'tr:':
        sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP - Technology record');
        $this->processTechnologyRecord($drmcSuffix);

        break;

      case 'ar:':
        sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP - Artwork record');
        $this->processArtworkRecord($drmcSuffix);

        break;

      default:
        throw new sfException('Parameter not recognized '.$this->resource);
    }

    parent::process();
  }

  protected function processTechnologyRecord($drmcSuffix)
  {
    if (ctype_digit($drmcSuffix))
    {
      $resource = QubitInformationObject::getById($drmcSuffix);
    }
    else
    {
      $criteria = new Criteria;
      $criteria->add(QubitSlug::SLUG, $drmcSuffix);
      $criteria->addJoin(QubitSlug::OBJECT_ID, QubitInformationObject::ID);

      $resource = QubitInformationObject::getOne($criteria);
    }

    if (null === $resource)
    {
      throw new sfException('Technology record with the given slug/id cannot be found');
    }

    if ($resource->levelOfDescriptionId !== sfConfig::get('app_drmc_lod_supporting_technology_record_id'))
    {
      throw new sfException('The given slug/id doesn\'t belong to a technology record');
    }

    // Get root technology record
    if ($resource->parentId != QubitInformationObject::ROOT_ID)
    {
      $criteria = new Criteria;
      $criteria->add(QubitInformationObject::LFT, $resource->lft, Criteria::LESS_THAN);
      $criteria->add(QubitInformationObject::RGT, $resource->rgt, Criteria::GREATER_THAN);
      $criteria->add(QubitInformationObject::PARENT_ID, QubitInformationObject::ROOT_ID);

      $rootTechRecord = QubitInformationObject::getOne($criteria);
    }

    if (isset($rootTechRecord))
    {
      list($aipIo, $aip) = $this->addAip($resource, $rootTechRecord);
    }
    else
    {
      list($aipIo, $aip) = $this->addAip($resource, $resource);
    }

    $this->addDigitalObjects($aipIo);

    // Create relation between AIP and tech record
    $relation = new QubitRelation;
    $relation->object = $resource;
    $relation->subject = $aip;
    $relation->typeId = QubitTerm::AIP_RELATION_ID;
    $relation->indexOnSave = false;
    $relation->save();

    if (isset($rootTechRecord))
    {
      // Create relation between AIP and the root tech record
      $relation = new QubitRelation;
      $relation->object = $rootTechRecord;
      $relation->subject = $aip;
      $relation->typeId = QubitTerm::AIP_RELATION_ID;
      $relation->indexOnSave = false;
      $relation->save();

      // Add AIP to the root tech record in ES
      QubitSearch::getInstance()->update($rootTechRecord);
    }

    // Add related digital objects to the AIP in ES
    // and save AIP data for the tech record in ES
    QubitSearch::getInstance()->update($aip);
    QubitSearch::getInstance()->update($resource);
  }

  protected function processArtworkRecord($tmsObjectId)
  {
    // Check for existing artwork
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::IDENTIFIER, $tmsObjectId);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_artwork_record_id'));

    if (null === $artwork = QubitInformationObject::getOne($criteria))
    {
      // Or create new one
      $artwork = new QubitInformationObject;
      $artwork->identifier = $tmsObjectId;
      $artwork->parentId = QubitInformationObject::ROOT_ID;
      $artwork->levelOfDescriptionId = sfConfig::get('app_drmc_lod_artwork_record_id');
      $artwork->setPublicationStatusByName('Published');
      $artwork->indexOnSave = false;
      $artwork->save();
    }

    $fetchTms = new arFetchTms;
    $fetchTms->processArtwork($artwork);

    // Check if the AIP is related to a TMSComponent
    if (null != ($componentNumber = $this->metsParser->getAipRelatedComponentNumber()))
    {
      // Check for existing TMSComponent
      $criteria = new Criteria;
      $criteria->addJoin(QubitProperty::ID, QubitPropertyI18n::ID);
      $criteria->add(QubitProperty::NAME, 'ComponentNumber');
      $criteria->add(QubitPropertyI18n::VALUE, $componentNumber);

      if (null !== $property = QubitProperty::getOne($criteria))
      {
        $component = QubitInformationObject::getById($property->objectId);
      }
    }

    // Relate AIP to component directly if set and found
    if (isset($component))
    {
      list($aipIo, $aip) = $this->addAip($component, $artwork);

      $this->addDigitalObjects($aipIo);

      $relation = new QubitRelation;
      $relation->object = $component;
      $relation->subject = $aip;
      $relation->typeId = QubitTerm::AIP_RELATION_ID;
      $relation->indexOnSave = false;
      $relation->save();

      // Add AIP to the component in ES
      QubitSearch::getInstance()->update($component);
    }
    // Otherwise, relate AIP with intermediate components level
    else
    {
      $criteria = new Criteria;
      $criteria->add(QubitInformationObject::PARENT_ID, $artwork->id);
      $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_description_id'));

      // $fetchTms->processArtwork makes sure an intermediate level exists
      $components = QubitInformationObject::getOne($criteria);
      list($aipIo, $aip) = $this->addAip($components, $artwork);

      $this->addDigitalObjects($aipIo);
    }

    // Create relation between AIP and artwork in all cases
    $relation = new QubitRelation;
    $relation->object = $artwork;
    $relation->subject = $aip;
    $relation->typeId = QubitTerm::AIP_RELATION_ID;
    $relation->indexOnSave = false;
    $relation->save();

    // Add related digital objects and ingestionUser to the AIP in ES
    // and save AIP and components data for the artwork in ES
    QubitSearch::getInstance()->update($aip);
    QubitSearch::getInstance()->update($aipIo);
    QubitSearch::getInstance()->update($artwork);
  }

  /*
   * @param QubitInformationObject $informationObjectParent  Parent of the new AIP information object
   * @param QubitInformationObject $partOfObject             New AIP will be link to this object using partOf
   * @return array Two elements, new AIP information object and new AIP
   */
  protected function addAip($informationObjectParent, $partOfObject)
  {
    // Create intermediate information object "AIP"
    $aipIo = new QubitInformationObject;
    $aipIo->parentId = $informationObjectParent->id;
    $aipIo->levelOfDescriptionId = sfConfig::get('app_drmc_lod_aip_id');
    $aipIo->setPublicationStatusByName('Published');
    $aipIo->title = 'AIP';

    // Add main object data in METS file to the AIP intermediate level
    if (null != ($dmdSec = $this->metsParser->getMainDmdSec()))
    {
      $this->metsParser->processDmdSec($dmdSec, $aipIo);
    }

    $aipIo->indexOnSave = false;
    $aipIo->save();

    sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP - $aipIo created '.$aipIo->id);

    $parts = pathinfo($this->filename);
    $filename = $parts['basename'];

    // Store AIP data
    $aip = new QubitAip;
    $aip->uuid = $this->aipUUID;
    $aip->filename = substr($filename, 0, -37);
    $aip->digitalObjectCount = count($this->getFilesFromDirectory($this->filename.DIRECTORY_SEPARATOR.'/objects'));
    $aip->partOf = $partOfObject->id;
    $aip->sizeOnDisk = $this->metsParser->getAipSizeOnDisk();
    $aip->createdAt = $this->metsParser->getAipCreationDate();
    $aip->indexOnSave = false;
    $aip->save();

    if (null != ($username = $this->metsParser->getAipIngestionUsername()))
    {
      QubitProperty::addUnique($aip->id, 'ingestionUser', $username, array('indexOnSave' => false));
    }

    // Add parent title of the AIP information object as attachedTo property for the AIP
    QubitProperty::addUnique($aip->id, 'attachedTo', $informationObjectParent->getTitle(array('sourceCulture' => true)), array('indexOnSave' => false));

    sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP - $aip created '.$aip->id);

    return array($aipIo, $aip);
  }

  /**
   * Given the AIP information object ($aipIo), this function will nest one file
   * information object for each file referenced in the METS file. Additionally,
   * a digital object will be attached if the file has been included in the DIP.
   *
   * @param $aipIo QubitInformationObject
   */
  protected function addDigitalObjects($aipIo)
  {
    // Create child file information object for AIP METS file before checking fileGrp
    $child = new QubitInformationObject;
    $child->parentId = $aipIo->id;
    $child->levelOfDescriptionId = sfConfig::get('app_drmc_lod_digital_object_id');
    $child->setPublicationStatusByName('Published');
    $child->title = 'METS.'.$this->aipUUID.'.xml';

    // Add digital object
    $metsPath = $this->filename.DIRECTORY_SEPARATOR.'METS.'.$this->aipUUID.'.xml';
    if (is_readable($metsPath))
    {
      $digitalObject = new QubitDigitalObject;
      $digitalObject->assets[] = new QubitAsset($metsPath);
      $digitalObject->usageId = QubitTerm::MASTER_ID;
      $child->digitalObjects[] = $digitalObject;
    }

    $child->indexOnSave = false;
    $child->save();

    // Store relative path within AIP and AIP UUID
    QubitProperty::addUnique($child->id, 'original_relative_path_within_aip', 'METS.'.$this->aipUUID.'.xml', array('indexOnSave' => false));
    QubitProperty::addUnique($child->id, 'aipUUID', $this->aipUUID, array('indexOnSave' => false));

    // Add child to ES
    QubitSearch::getInstance()->update($child);

    $files = $this->metsParser->getAllFiles();
    if (false === $files || count($files) === 0)
    {
      sfContext::getInstance()->getLogger()->err('METSArchivematicaDIP - addDigitalObjects(): fileGrp not found');
      return;
    }

    foreach ($files as $file)
    {
      if(!isset($file['ID']))
      {
        continue;
      }

      // File ID and UUID
      $fileId = (string)$file['ID'];
      $uuid = $this->mappings['uuidMapping'][$fileId];

      // Parent fileGrp and use
      $parent = current($file->xpath('parent::*'));
      $use = (string)$parent['USE'];

      sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP - objectUUID: '.$uuid.' ('.$use.')');

      // Check availability of FLocat
      $this->metsParser->registerNamespaces($file, array('m' => 'mets'));

      if (null === $fLocat = $file->xpath('m:FLocat')[0])
      {
        sfContext::getInstance()->getLogger()->err('METSArchivematicaDIP - FLocat not found');
        continue;
      }

      // Store relative path within AIP
      $fLocatAttrs = $fLocat->attributes('xlink', true);
      if (empty($fLocatAttrs->href))
      {
        sfContext::getInstance()->getLogger()->err('METSArchivematicaDIP - FLocat[href] not found or empty');
        continue;
      }

      // AIP paths
      $relativePathWithinAip = $fLocatAttrs->href;
      $relativePathWithinAipParts = pathinfo($relativePathWithinAip);
      sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP -             [path->AIP] '.$relativePathWithinAip);

      // Don't add METS file in submissionDocumentation
      if ($use == 'submissionDocumentation' && $relativePathWithinAipParts['basename'] == 'METS.xml')
      {
        sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP -             METS file in submissionDocumentation is not added');
        continue;
      }

      // DIP paths
      if (false === $absolutePathWithinDip = $this->getAccessCopyPath($uuid))
      {
        // This is actually not too bad, maybe normalization failed but we still
        // want to have an information object
        sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP -             Access copy cannot be found in the DIP');
      }
      else
      {
        $absolutePathWithinDipParts = pathinfo($absolutePathWithinDip);
        $relativePathWithinDip = 'objects'.DIRECTORY_SEPARATOR.$absolutePathWithinDipParts['basename'];
        sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP -             [path->DIP] '.$relativePathWithinDip);
      }

      // Create child file information object
      $child = new QubitInformationObject;
      $child->parentId = $aipIo->id;
      $child->levelOfDescriptionId = sfConfig::get('app_drmc_lod_digital_object_id');
      $child->setPublicationStatusByName('Published');
      $child->title = $relativePathWithinAipParts['basename']; // Notice that we use the filename of the original file, not the access copy
      $child->indexOnSave = false;

      // Files other than the ones under USE="original" are not included
      if ($use === 'original' && false !== $absolutePathWithinDip && is_readable($absolutePathWithinDip))
      {
        // Add digital object
        $digitalObject = new QubitDigitalObject;
        $digitalObject->assets[] = new QubitAsset($absolutePathWithinDip);
        $digitalObject->usageId = QubitTerm::MASTER_ID;
        $child->digitalObjects[] = $digitalObject;
      }

      // Process metatadata from METS file
      if ((null !== $dmdId = $this->mappings['dmdMapping'][$fileId])
        && (null !== $dmdSec = $this->metsParser->getDmdSec($dmdId)))
      {
        $child = $this->metsParser->processDmdSec($dmdSec, $child);
      }

      $child->save();

      // Use property to augment digital object with relative path within AIP
      $property = new QubitProperty;
      $property->objectId = $child->id;
      $property->setName('original_relative_path_within_aip');
      $property->setValue($relativePathWithinAip);
      $property->indexOnSave = false;
      $property->save();

      // Storage UUIDs
      QubitProperty::addUnique($child->id, 'objectUUID', $uuid, array('indexOnSave' => false));
      QubitProperty::addUnique($child->id, 'aipUUID', $this->aipUUID, array('indexOnSave' => false));

      // Add required data from METS file to the database
      $error = $this->metsParser->addMetsDataToInformationObject($child, $uuid);
      if (isset($error))
      {
        sfContext::getInstance()->getLogger()->info('METSArchivematicaDIP -             ' . $error);
      }

      $child->indexOnSave = true;
      $child->save();
    }

    return;
  }

  protected function getAccessCopyPath($uuid)
  {
    $glob = $this->filename.DIRECTORY_SEPARATOR.'objects'.DIRECTORY_SEPARATOR.$uuid.'*';
    $matches = glob($glob, GLOB_NOSORT);
    if (empty($matches))
    {
      return false;
    }

    return current($matches);
  }
}
