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

class ApiAipsDownloadAction extends QubitApiAction
{
  protected function get($request)
  {
    if (!isset($request->url))
    {
      throw new QubitApiBadRequestException('Missing parameter: url');
    }

    if (!isset($request->filename))
    {
      throw new QubitApiBadRequestException('Missing parameter: filename');
    }

    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename='.$request->filename);
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');

    if (isset($request->filesize))
    {
      header('Content-Length: '.$request->filesize);
    }

    ob_clean();
    flush();

    // Proxy file from storage service
    $ch = curl_init($request->url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // Storage service redirects
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      sprintf('Authorization: ApiKey %s:%s', sfConfig::get('app_drmc_ss_user'), sfConfig::get('app_drmc_ss_api_key')),
      'User-Agent: DRMC',
    ));

    $response = curl_exec($ch);
    curl_close($ch);

    exit;
  }
}
