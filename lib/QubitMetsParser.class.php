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

class QubitMetsParser
{
  private $document, $resource;

  public function __construct($document, $options = array())
  {
    // Load document
    $this->document = $document;

    // Register namespaces
    $this->document->registerXPathNamespace('m', 'http://www.loc.gov/METS/');
    $this->document->registerXPathNamespace('p', 'info:lc/xmlns/premis-v2');
    $this->document->registerXPathNamespace('f', 'http://hul.harvard.edu/ois/xml/ns/fits/fits_output');
  }

  public function addMetsDataToInformationObject(&$resource, $objectUuid)
  {
    // Obtain amdSec id for objectUuid
    foreach ($this->document->xpath('//m:fileSec/m:fileGrp[@USE="original"]/m:file') as $item)
    {
      if (false !== strrpos($item['ID'], $objectUuid))
      {
        $amdSecId = $item['ADMID'];

        break;
      }
    }

    if (!isset($amdSecId))
    {
      return 'AMD section was not found for object UUID: ' . $objectUuid;
    }

    $this->objectXpath = '//m:amdSec[@ID="'.$amdSecId.'"]/m:techMD/m:mdWrap[@MDTYPE="PREMIS:OBJECT"]/m:xmlData/p:object/';

    $this->resource = $resource;

    $this->loadPremisObjectData();
    $this->loadFitsAudioData();
    $this->loadFitsDocumentData();
    $this->loadFitsTextData();
    $this->loadMediainfoData();
    $this->loadFormatData();
    $this->loadEventsData($amdSecId);
    $this->loadAgentsData($amdSecId);
  }

  private function loadPremisObjectData()
  {
    $premisObject = new QubitPremisObject;

    $fields = array(
      'filename' => array(
        'xpath' => $this->objectXpath.'p:originalName',
        'type' => 'lastPartOfPath'),
      'puid' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:format/p:formatRegistry[p:formatRegistryName="PRONOM"]/p:formatRegistryKey',
        'type' => 'string'),
      'lastModified' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/f:fits/f:toolOutput/f:tool/repInfo/lastModified',
        'type' => 'date'),
      'size' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:size',
        'type' => 'string'),
      'mimeType' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/f:fits/f:toolOutput/f:tool/fileUtilityOutput/mimetype',
        'type' => 'string'));

    foreach ($fields as $fieldName => $options)
    {
      $value = $this->getFieldValue($this->document, $options['xpath'], $options['type']);
      if (!empty($value))
      {
        $premisObject->$fieldName = $value;
      }
    }

    $this->resource->premisObjects[] = $premisObject;
  }

  private function loadFitsAudioData()
  {
    $fitsAudio = array();
    $audioXpath = $this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/f:fits/f:metadata/f:audio/';

    $fields = array(
      'bitDepth' => array(
        'xpath' => $audioXpath.'f:bitDepth',
        'type' => 'string'),
      'sampleRate' => array(
        'xpath' => $audioXpath.'f:sampleRate',
        'type' => 'string'),
      'channels' => array(
        'xpath' => $audioXpath.'f:channels',
        'type' => 'string'),
      'dataEncoding' => array(
        'xpath' => $audioXpath.'f:audioDataEncoding',
        'type' => 'string'),
      'offset' => array(
        'xpath' => $audioXpath.'f:offset',
        'type' => 'string'),
      'byteOrder' => array(
        'xpath' => $audioXpath.'f:byteOrder',
        'type' => 'string'));

    foreach ($fields as $fieldName => $options)
    {
      $value = $this->getFieldValue($this->document, $options['xpath'], $options['type']);
      if (!empty($value))
      {
        $fitsAudio[$fieldName] = $value;
      }
    }

    if (!empty($fitsAudio))
    {
      QubitProperty::addUnique($this->resource->id, 'fitsAudio', serialize($fitsAudio), array('scope' => 'premisData', 'indexOnSave' => false));
    }
  }

  private function loadFitsDocumentData()
  {
    $fitsDocument = array();
    $documentXpath = $this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/f:fits/f:metadata/f:document/';

    $fields = array(
      'title' => array(
        'xpath' => $documentXpath.'f:title',
        'type' => 'string'),
      'author' => array(
        'xpath' => $documentXpath.'f:author',
        'type' => 'string'),
      'pageCount' => array(
        'xpath' => $documentXpath.'f:pageCount',
        'type' => 'string'),
      'wordCount' => array(
        'xpath' => $documentXpath.'f:wordCount',
        'type' => 'string'),
      'characterCount' => array(
        'xpath' => $documentXpath.'f:characterCount',
        'type' => 'string'),
      'language' => array(
        'xpath' => $documentXpath.'f:language',
        'type' => 'string'),
      'isProtected' => array(
        'xpath' => $documentXpath.'f:isProtected',
        'type' => 'boolean'),
      'isRightsManaged' => array(
        'xpath' => $documentXpath.'f:isRightsManaged',
        'type' => 'boolean'),
      'isTagged' => array(
        'xpath' => $documentXpath.'f:isTagged',
        'type' => 'boolean'),
      'hasOutline' => array(
        'xpath' => $documentXpath.'f:hasOutline',
        'type' => 'boolean'),
      'hasAnnotations' => array(
        'xpath' => $documentXpath.'f:hasAnnotations',
        'type' => 'boolean'),
      'hasForms' => array(
        'xpath' => $documentXpath.'f:hasForms',
        'type' => 'boolean'));

    foreach ($fields as $fieldName => $options)
    {
      $value = $this->getFieldValue($this->document, $options['xpath'], $options['type']);
      if (!empty($value))
      {
        $fitsDocument[$fieldName] = $value;
      }
    }

    if (!empty($fitsDocument))
    {
      QubitProperty::addUnique($this->resource->id, 'fitsDocument', serialize($fitsDocument), array('scope' => 'premisData', 'indexOnSave' => false));
    }
  }

  private function loadFitsTextData()
  {
    $fitsText = array();
    $textXpath = $this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/f:fits/f:metadata/f:text/';

    $fields = array(
      'linebreak' => array(
        'xpath' => $textXpath.'f:linebreak',
        'type' => 'string'),
      'charset' => array(
        'xpath' => $textXpath.'f:charset',
        'type' => 'string'),
      'markupBasis' => array(
        'xpath' => $textXpath.'f:markupBasis',
        'type' => 'string'),
      'markupBasisVersion' => array(
        'xpath' => $textXpath.'f:markupBasisVersion',
        'type' => 'string'),
      'markupLanguage' => array(
        'xpath' => $textXpath.'f:markupLanguage',
        'type' => 'string'));

    foreach ($fields as $fieldName => $options)
    {
      $value = $this->getFieldValue($this->document, $options['xpath'], $options['type']);
      if (!empty($value))
      {
        $fitsText[$fieldName] = $value;
      }
    }

    if (!empty($fitsText))
    {
      QubitProperty::addUnique($this->resource->id, 'fitsText', serialize($fitsText), array('scope' => 'premisData', 'indexOnSave' => false));
    }
  }

  private function loadMediainfoData()
  {
    $trackFields = array(
      'count' => array(
        'xpath' => 'Count',
        'type' => 'integer'),
      'videoFormatList' => array(
        'xpath' => 'Video_Format_List',
        'type' => 'string'),
      'videoFormatWithHintList' => array(
        'xpath' => 'Video_Format_WithHint_List',
        'type' => 'string'),
      'codecsVideo' => array(
        'xpath' => 'Codecs_Video',
        'type' => 'string'),
      'videoLanguageList' => array(
        'xpath' => 'Video_Language_List',
        'type' => 'string'),
      'audioFormatList' => array(
        'xpath' => 'Audio_Format_List',
        'type' => 'string'),
      'audioFormatWithHintList' => array(
        'xpath' => 'Audio_Format_WithHint_List',
        'type' => 'string'),
      'audioCodecs' => array(
        'xpath' => 'Audio_codecs',
        'type' => 'string'),
      'audioLanguageList' => array(
        'xpath' => 'Audio_Language_List',
        'type' => 'string'),
      'completeName' => array(
        'xpath' => 'Complete_name',
        'type' => 'string'),
      'fileName' => array(
        'xpath' => 'File_name',
        'type' => 'string'),
      'fileExtension' => array(
        'xpath' => 'File_extension',
        'type' => 'string'),
      'format' => array(
        'xpath' => 'Format',
        'type' => 'string'),
      'formatInfo' => array(
        'xpath' => 'Format_Info',
        'type' => 'string'),
      'formatUrl' => array(
        'xpath' => 'Format_Url',
        'type' => 'string'),
      'formatProfile' => array(
        'xpath' => 'Format_profile',
        'type' => 'string'),
      'formatSettings' => array(
        'xpath' => 'Format_settings',
        'type' => 'string'),
      'formatSettingsCabac' => array(
        'xpath' => 'Format_settings__CABAC',
        'type' => 'string'),
      'formatSettingsReFrames' => array(
        'xpath' => 'Format_settings__ReFrames',
        'type' => 'string'),
      'formatSettingsGop' => array(
        'xpath' => 'Format_settings__GOP',
        'type' => 'string'),
      'formatExtensionsUsuallyUsed' => array(
        'xpath' => 'Format_Extensions_usually_used',
        'type' => 'string'),
      'commercialName' => array(
        'xpath' => 'Commercial_name',
        'type' => 'string'),
      'internetMediaType' => array(
        'xpath' => 'Internet_media_type',
        'type' => 'string'),
      'codecId' => array(
        'xpath' => 'Codec_ID',
        'type' => 'string'),
      'codecIdInfo' => array(
        'xpath' => 'Codec_ID_Info',
        'type' => 'string'),
      'codecIdUrl' => array(
        'xpath' => 'Codec_ID_Url',
        'type' => 'string'),
      'codec' => array(
        'xpath' => 'Codec',
        'type' => 'string'),
      'codecFamily' => array(
        'xpath' => 'Codec_Family',
        'type' => 'string'),
      'codecInfo' => array(
        'xpath' => 'Codec_Info',
        'type' => 'string'),
      'codecUrl' => array(
        'xpath' => 'Codec_Url',
        'type' => 'string'),
      'codecCc' => array(
        'xpath' => 'Codec_CC',
        'type' => 'string'),
      'codecProfile' => array(
        'xpath' => 'Codec_profile',
        'type' => 'string'),
      'codecSettings' => array(
        'xpath' => 'Codec_settings',
        'type' => 'string'),
      'codecSettingsCabac' => array(
        'xpath' => 'Codec_settings__CABAC',
        'type' => 'string'),
      'codecSettingsRefFrames' => array(
        'xpath' => 'Codec_Settings_RefFrames',
        'type' => 'string'),
      'codecExtensionsUsuallyUsed' => array(
        'xpath' => 'Codec_Extensions_usually_used',
        'type' => 'string'),
      'fileSize' => array(
        'xpath' => 'File_size',
        'type' => 'firstInteger'),
      'duration' => array(
        'xpath' => 'Duration',
        'type' => 'firstInteger'),
      'bitRate' => array(
        'xpath' => 'Bit_rate',
        'type' => 'firstInteger'),
      'bitRateMode' => array(
        'xpath' => 'Bit_rate_mode',
        'type' => 'string'),
      'overallBitRate' => array(
        'xpath' => 'Overall_bit_rate',
        'type' => 'firstInteger'),
      'channels' => array(
        'xpath' => 'Channel_s_',
        'type' => 'firstInteger'),
      'channelPositions' => array(
        'xpath' => 'Channel_positions',
        'type' => 'string'),
      'samplingRate' => array(
        'xpath' => 'Sampling_rate',
        'type' => 'firstInteger'),
      'samplesCount' => array(
        'xpath' => 'Samples_count',
        'type' => 'firstInteger'),
      'compressionMode' => array(
        'xpath' => 'Compression_mode',
        'type' => 'string'),
      'width' => array(
        'xpath' => 'Width',
        'type' => 'firstInteger'),
      'height' => array(
        'xpath' => 'Height',
        'type' => 'firstInteger'),
      'pixelAspectRatio' => array(
        'xpath' => 'Pixel_aspect_ratio',
        'type' => 'firstFloat'),
      'displayAspectRatio' => array(
        'xpath' => 'Display_aspect_ratio',
        'type' => 'firstStringWithTwoPoints'),
      'rotation' => array(
        'xpath' => 'Rotation',
        'type' => 'firstFloat'),
      'frameRateMode' => array(
        'xpath' => 'Frame_rate_mode',
        'type' => 'string'),
      'frameRate' => array(
        'xpath' => 'Frame_rate',
        'type' => 'firstFloat'),
      'frameCount' => array(
        'xpath' => 'Frame_count',
        'type' => 'firstInteger'),
      'resolution' => array(
        'xpath' => 'Resolution',
        'type' => 'firstInteger'),
      'colorimetry' => array(
        'xpath' => 'Colorimetry',
        'type' => 'string'),
      'colorSpace' => array(
        'xpath' => 'Color_space',
        'type' => 'string'),
      'chromaSubsampling' => array(
        'xpath' => 'Chroma_subsampling',
        'type' => 'string'),
      'bitDepth' => array(
        'xpath' => 'Bit_depth',
        'type' => 'firstInteger'),
      'scanType' => array(
        'xpath' => 'Scan_type',
        'type' => 'string'),
      'interlacement' => array(
        'xpath' => 'Interlacement',
        'type' => 'string'),
      'bitsPixelFrame' => array(
        'xpath' => 'Bits__Pixel_Frame_',
        'type' => 'firstFloat'),
      'streamSize' => array(
        'xpath' => 'Stream_size',
        'type' => 'firstInteger'),
      'proportionOfThisStream' => array(
        'xpath' => 'Proportion_of_this_stream',
        'type' => 'firstFloat'),
      'headerSize' => array(
        'xpath' => 'HeaderSize',
        'type' => 'firstInteger'),
      'dataSize' => array(
        'xpath' => 'DataSize',
        'type' => 'firstInteger'),
      'footerSize' => array(
        'xpath' => 'FooterSize',
        'type' => 'firstInteger'),
      'language' => array(
        'xpath' => 'Language',
        'type' => 'string'),
      'colorPrimaries' => array(
        'xpath' => 'Color_primaries',
        'type' => 'string'),
      'transferCharacteristics' => array(
        'xpath' => 'Transfer_characteristics',
        'type' => 'string'),
      'matrixCoefficients' => array(
        'xpath' => 'Matrix_coefficients',
        'type' => 'string'),
      'isStreamable' => array(
        'xpath' => 'IsStreamable',
        'type' => 'boolean'),
      'writingApplication' => array(
        'xpath' => 'Writing_application',
        'type' => 'string'),
      'fileLastModificationDate' => array(
        'xpath' => 'File_last_modification_date',
        'type' => 'date'),
      'fileLastModificationDateLocal' => array(
        'xpath' => 'File_last_modification_date__local_',
        'type' => 'date'));

    // Get all tracks
    $mediainfoTracks = $this->document->xpath($this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/Mediainfo/File/track');
    $oldMets = false;

    // Check xpath query for old Archivematica METS files if no tracks were found
    if (1 > count($mediainfoTracks))
    {
      $mediainfoTracks = $this->document->xpath($this->objectXpath.'p:objectCharacteristics/p:objectCharacteristicsExtension/p:Mediainfo/p:File/p:track');
      $oldMets = true;
    }

    foreach ($mediainfoTracks as $track)
    {
      $track->registerXPathNamespace('p', 'info:lc/xmlns/premis-v2');

      $esTrack = array();

      // Load track data
      foreach ($trackFields as $fieldName => $options)
      {
        // Add namespace to xpath query for old METS
        if ($oldMets)
        {
          $options['xpath'] = 'p:'.$options['xpath'];
        }

        $value = $this->getFieldValue($track, $options['xpath'], $options['type']);
        if (!empty($value))
        {
          $esTrack[$fieldName] = $value;
        }
      }

      if (!empty($esTrack))
      {
        // Add track by type
        $type = $track->xpath('@type');
        switch ($type[0])
        {
          case 'General':
            QubitProperty::addUnique($this->resource->id, 'mediainfoGeneralTrack', serialize($esTrack), array('scope' => 'premisData', 'indexOnSave' => false));

            break;

          case 'Video':
            QubitProperty::addUnique($this->resource->id, 'mediainfoVideoTrack', serialize($esTrack), array('scope' => 'premisData', 'indexOnSave' => false));

            break;

          case 'Audio':
            QubitProperty::addUnique($this->resource->id, 'mediainfoAudioTrack', serialize($esTrack), array('scope' => 'premisData', 'indexOnSave' => false));

            break;
        }
      }
    }
  }

  private function loadFormatData()
  {
    $format = array();

    $fields = array(
      'name' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:format/p:formatDesignation/p:formatName',
        'type' => 'string'),
      'version' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:format/p:formatDesignation/p:formatVersion',
        'type' => 'string'),
      'registryName' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:format/p:formatRegistry/p:formatRegistryName',
        'type' => 'string'),
      'registryKey' => array(
        'xpath' => $this->objectXpath.'p:objectCharacteristics/p:format/p:formatRegistry/p:formatRegistryKey',
        'type' => 'string'));

    foreach ($fields as $fieldName => $options)
    {
      // Allow empty values in format data
      $format[$fieldName] = $value = $this->getFieldValue($this->document, $options['xpath'], $options['type']);
    }

    if (!empty($format))
    {
      QubitProperty::addUnique($this->resource->id, 'format', serialize($format), array('scope' => 'premisData', 'indexOnSave' => false));
    }
  }

  private function loadEventsData($amdSecId)
  {
    $eventFields = array(
      'type' => array(
        'xpath' => 'p:eventType',
        'type' => 'string'),
      'dateTime' => array(
        'xpath' => 'p:eventDateTime',
        'type' => 'date'),
      'detail' => array(
        'xpath' => 'p:eventDetail',
        'type' => 'string'),
      'outcome' => array(
        'xpath' => 'p:eventOutcomeInformation/p:eventOutcome',
        'type' => 'string'),
      'outcomeDetailNote' => array(
        'xpath' => 'p:eventOutcomeInformation/p:eventOutcomeDetail/p:eventOutcomeDetailNote',
        'type' => 'string'));

    $linkingAgentIdentifierFields = array(
      'type' => array(
        'xpath' => 'p:linkingAgentIdentifierType',
        'type' => 'string'),
      'value' => array(
        'xpath' => 'p:linkingAgentIdentifierValue',
        'type' => 'string'));

    // Get all events
    foreach ($this->document->xpath('//m:amdSec[@ID="'.$amdSecId.'"]/m:digiprovMD/m:mdWrap[@MDTYPE="PREMIS:EVENT"]/m:xmlData/p:event') as $item)
    {
      $item->registerXPathNamespace('p', 'info:lc/xmlns/premis-v2');

      $event = array();

      // Load event data
      foreach ($eventFields as $fieldName => $options)
      {
        $value = $this->getFieldValue($item, $options['xpath'], $options['type']);
        if (!empty($value))
        {
          $event[$fieldName] = $value;
        }
      }

      // Get all event linking agent identifiers
      foreach ($item->xpath('p:linkingAgentIdentifier') as $linkingAgent)
      {
        $linkingAgent->registerXPathNamespace('p', 'info:lc/xmlns/premis-v2');

        $linkingAgentIdentifier = array();

        // Load linking agent identifier data
        foreach ($linkingAgentIdentifierFields as $fieldName => $options)
        {
          $value = $this->getFieldValue($linkingAgent, $options['xpath'], $options['type']);
          if (!empty($value))
          {
            $linkingAgentIdentifier[$fieldName] = $value;
          }
        }

        // Add linking agent identifier to event
        $event['linkingAgentIdentifier'][] = $linkingAgentIdentifier;
      }

      // Add event dateTime to IO's dateIngested field if it's the ingestion event
      if (isset($event['type']) && isset($event['dateTime']) && $event['type'] == 'ingestion')
      {
        $this->resource->premisObjects[0]->dateIngested = $event['dateTime'];
      }

      if (!empty($event))
      {
        // Format identification event is stored apart
        if (isset($event['type']) && $event['type'] == 'format identification')
        {
          QubitProperty::addUnique($this->resource->id, 'formatIdentificationEvent', serialize($event), array('scope' => 'premisData', 'indexOnSave' => false));
        }
        else
        {
          QubitProperty::addUnique($this->resource->id, 'otherEvent', serialize($event), array('scope' => 'premisData', 'indexOnSave' => false));
        }
      }
    }
  }

  private function loadAgentsData($amdSecId)
  {
    $agentFields = array(
      'identifierType' => array(
        'xpath' => 'm:agentIdentifier/m:agentIdentifierType',
        'type' => 'string'),
      'identifierValue' => array(
        'xpath' => 'm:agentIdentifier/m:agentIdentifierValue',
        'type' => 'string'),
      'name' => array(
        'xpath' => 'm:agentName',
        'type' => 'string'),
      'type' => array(
        'xpath' => 'm:agentType',
        'type' => 'string'));

    foreach ($this->document->xpath('//m:amdSec[@ID="'.$amdSecId.'"]/m:digiprovMD/m:mdWrap[@MDTYPE="PREMIS:AGENT"]/m:xmlData/m:agent') as $item)
    {
      $item->registerXPathNamespace('m', 'http://www.loc.gov/METS/');

      $agent = array();

      foreach ($agentFields as $fieldName => $options)
      {
        $value = $this->getFieldValue($item, $options['xpath'], $options['type']);
        if (!empty($value))
        {
          $agent[$fieldName] = $value;
        }
      }

      if (!empty($agent))
      {
        QubitProperty::addUnique($this->resource->id, 'agent', serialize($agent), array('scope' => 'premisData', 'indexOnSave' => false));
      }
    }
  }

  private function getFieldValue($element, $xpath, $type)
  {
    if (1 > count($results = $element->xpath($xpath)))
    {
      return;
    }

    switch ($type)
    {
      case 'lastPartOfPath':
        $parts = explode('/', (string)$results[0]);
        return end($parts);

      case 'string':
        return (string)$results[0];

      case 'date':
        return arElasticSearchPluginUtil::convertDate((string)$results[0]);

      case 'boolean':
        return strtolower((string)$results[0]) == 'yes' ? true : false;

      case 'integer':
        return (integer)$results[0];

      case 'firstInteger':
        foreach ($results as $item)
        {
          if (ctype_digit((string)$item))
          {
            return (integer)$item;
          }
        }

      case 'firstFloat':
        foreach ($results as $item)
        {
          if (is_float(floatval((string)$item)))
          {
            return floatval((string)$item);
          }
        }

      case 'firstStringWithTwoPoints':
        foreach ($results as $item)
        {
          if (strrpos((string)$item, ':') !== false)
          {
            return (string)$item;
          }
        }
    }
  }
}
