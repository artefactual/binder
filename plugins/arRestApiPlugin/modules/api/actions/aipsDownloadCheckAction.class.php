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
 * Example:
 *
 *  $ curl -v \
 *    http://HOSTNAME:8001/api/aips/UUID/download?reason=REASON&file_id=FILE_INFORMATION_OBJECT_ID
 *
 */
class ApiAipsDownloadCheckAction extends QubitApiAction
{
  protected function get($request)
  {
    if (!isset($request->reason))
    {
      throw new QubitApiBadRequestException('Missing parameter: reason');
    }

    if (strlen($request->reason) < 10)
    {
      throw new QubitApiBadRequestException('Parameter reason is not long enough');
    }

    // Get AIP data from ES to verify it exists and to log access
    $aip = QubitApiAip::getResults($request);

    // Log access attempt to AIP/file
    $this->logAccessAttempt($request, $aip['id']);

    $checkResult = array();

    // Get configuration needed to access storage service
    $ssConfig = array();
    $ssEnvVars = array(
      'ARCHIVEMATICA_SS_HOST' => '127.0.0.1',
      'ARCHIVEMATICA_SS_PORT' => '8000'
    );

    // Determine configuration based on environment variable settings
    foreach ($ssEnvVars as $var => $default)
    {
      // Get Archivematica storage service host
      $value = getenv($var);

      if (!$value && !$default)
      {
        throw new QubitApiException($var + ' not configured', 500);
      }

      $ssConfig[$var] = ($value) ? $value : $default;
    }

    // Assemble storage service URL
    $storageServiceUrl = 'http://'. $ssConfig['ARCHIVEMATICA_SS_HOST'];
    $storageServiceUrl .= ':'. $ssConfig['ARCHIVEMATICA_SS_PORT'];
    $aipUrl = $storageServiceUrl .'/api/v2/file';

    // Determine filename of AIP via REST call to storage service
    $aipInfoUrl = $aipUrl .'/'. $request->uuid .'?format=json';

    $ch = curl_init($aipInfoUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // Storage service redirects
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $aipInfoJson = curl_exec($ch);
    $error = curl_error($ch);

    curl_close($ch);

    // Handle possible errors
    if ($aipInfoJson === false)
    {
      $checkResult['available'] = false;
      $checkResult['reason'] = 'Request to Archivematica Storage Service failed: '.$error.'. Please contact a system administrator.';

      return $checkResult;
    }

    curl_close($ch);

    $aipInfo = json_decode($aipInfoJson);
    $filename = basename($aipInfo->current_full_path) .'.tar';

    // Formalate URL depending on whether a single file is being extracted
    $downloadUrl = $aipUrl .'/'. $request->uuid .'/';
    $downloadUrl .= ($request->file_id) ? 'extract_file/' : 'download/';

    // If a single file is being extracted, augment with relative path to file
    if (isset($request->file_id))
    {
      // Retrieve relative path to file
      $criteria = new Criteria;
      $criteria->add(QubitProperty::NAME, 'original_relative_path_within_aip');
      $criteria->add(QubitProperty::OBJECT_ID, $request->file_id);
      $property = QubitProperty::getOne($criteria);

      $filenameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);
      $relativePathToFile = $filenameWithoutExtension .'/data/' . $property->value;
      $downloadUrl .= '?relative_path_to_file='. urlencode($relativePathToFile);
      $filename = basename($relativePathToFile);
    }

    $checkResult['url'] = $downloadUrl;
    $checkResult['filename'] = $filename;
    $checkResult = array_merge($checkResult, $this->checkStatus($downloadUrl));

    return $checkResult;
  }

  /**
   * Data is stored in property_i18n table as character-delimited (|) fields
   * (data, user ID, reason, path to file)
   *
   * Example log retrieval for AIP:
   *
   * SELECT * FROM property p INNER JOIN property_i18n pi ON p.id=pi.id WHERE p.object_id=457 ORDER BY pi.value;
   */
  protected function logAccessAttempt($request, $aipId)
  {
    // Log access to AIP
    $logEntry = new QubitAccessLog;
    $logEntry->objectId = isset($request->file_id) ? $request->file_id : $aipId;
    $logEntry->userId = $this->getUser()->getUserID();
    $logEntry->reason = $request->reason;
    $logEntry->date = date('Y-m-d H:i:s');

    // Access type can either by a full AIP or an AIP file
    if (isset($request->file_id))
    {
      $logEntry->typeId = QubitTerm::ACCESS_LOG_AIP_FILE_DOWNLOAD_ENTRY;
    }
    else
    {
      $logEntry->typeId = QubitTerm::ACCESS_LOG_AIP_DOWNLOAD_ENTRY;
    }

    $logEntry->save();
  }

  protected function checkStatus($url)
  {
    $checkResult = array();

    // Check AIP/file status from SS without download the file
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // Storage service redirects
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);

    $response = curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fileSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

    curl_close($ch);

    // AIP/file available
    if ($responseCode == 200)
    {
      $checkResult['available'] = true;
      $checkResult['filesize'] = $fileSize;

      return $checkResult;
    }

    $checkResult['available'] = false;

    // If the AIP/file is not available, ask SS again
    // including the response body to get the error message
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseError = curl_error($ch);

    curl_close($ch);

    // Storage service problem
    if ($response === false)
    {
      $checkResult['reason'] = 'Request to Archivematica Storage Service failed: '.$responseError.'. Please contact a system administrator.';

      return $checkResult;
    }

    $response = json_decode($response);

    switch ($responseCode)
    {
      case 202:
        $checkResult['reason'] = 'Requested [file/AIP] is not available in Arkivum cache. An administrator has been contacted and will notify you when the requested [file/AIP] is ready for download.';

        break;

      case 404:
        $checkResult['reason'] = 'File not found! Please contact a system administrator.';

        break;

      case 502:
        $checkResult['reason'] = 'Arkivum returned an error: '.$response->message.'. Please contact a system administrator.';

        break;

      default:
        $checkResult['reason'] = 'An unknown error occurred. Please contact a system administrator.';

        break;
    }

    return $checkResult;
  }
}
