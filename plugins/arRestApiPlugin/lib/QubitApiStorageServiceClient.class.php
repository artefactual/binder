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

class QubitApiStorageServiceClient
{
  public $status;

  public function get($urlPath)
  {
    $this->verifyPipelineExists();
    return $this->request($urlPath);
  }

  public function post($urlPath, $postData)
  {
    $this->verifyPipelineExists();
    return $this->request($urlPath, $postData);
  }

  private function verifyPipelineExists()
  {
    $uuid = sfConfig::get('app_drmc_ss_pipeline_uuid');

    $this->request('api/v2/pipeline/'. $uuid);
    if ($this->status == 404)
    {
      throw new QubitApi404Exception('QubitApiStorageServiceClient pipeline UUID "'. $uuid .'" not found: '. $error, 404);
    }
  }

  private function request($urlPath, $postData = FALSE)
  {
    // Assemble storage server URL
    $storageServiceUrl = 'http://'. sfConfig::get('app_drmc_ss_host');
    $storageServiceUrl .= ':'. sfConfig::get('app_drmc_ss_port');
    $url = $storageServiceUrl .'/'. $urlPath;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // allow redirects
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = array(
      sprintf('Authorization: ApiKey %s:%s', sfConfig::get('app_drmc_ss_user'), sfConfig::get('app_drmc_ss_api_key')),
      'User-Agent: DRMC',
    );

    if ($postData)
    {
      curl_setopt($ch,CURLOPT_POST, count($postData));

      if (is_array($postData))
      {
        // Serialize POST data
        $postBody = '';
        foreach($postData as $key => $value)
        {
          $postBody .= $key .'='. urlencode($value) .'&';
        }

        rtrim($postBody, '&');
      }
      else
      {
        $postBody = $postData;
      }

      curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
      $headers = array_merge($headers, array('Content-type: application/json', 'Content-Length: '. strlen($postBody)));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    $this->status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Handle possible errors
    if ($result === false)
    {
      $error = curl_error($ch);
      curl_close($ch);

      sfContext::getInstance()->getLogger()->err('Error getting storage service data: '. $error);
      sfContext::getInstance()->getLogger()->err('URL: '. $url);

      throw new QubitApiException('QubitApiStorageServiceClient error: '. $error, 500);
    }
    curl_close($ch);

    return $result;
  }
}
