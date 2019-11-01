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

class ApiActivityIngestionAction extends QubitApiAction
{
  protected function get($request)
  {
    return $this->getResults();
  }

  protected function getResults()
  {
    $sql = <<<EOL
SELECT
  io.id,
  io.level_of_description_id,
  io18n.title,
  aip.filename,
  aip.size_on_disk,
  aip.created_at,
  aip.uuid,
  master_do.usage_id as master_usage_id,
  master_do.path as master_path,
  master_do.name as master_name,
  thumbnail_do.path as thumbnail_path,
  thumbnail_do.name as thumbnail_name
FROM
  aip
INNER JOIN information_object io
  ON aip.part_of = io.id
INNER JOIN information_object_i18n io18n
  ON io.id = io18n.id
LEFT JOIN digital_object master_do
  ON io.id = master_do.information_object_id
LEFT JOIN digital_object thumbnail_do
  ON master_do.id = thumbnail_do.parent_id and thumbnail_do.usage_id = ?
WHERE
  (io.level_of_description_id = ? OR io.level_of_description_id = ?)
ORDER BY
  aip.created_at DESC
LIMIT 20;
EOL;

    $results = QubitPdo::fetchAll($sql, array(
      QubitTerm::THUMBNAIL_ID,
      sfConfig::get('app_drmc_lod_artwork_record_id'),
      sfConfig::get('app_drmc_lod_supporting_technology_record_id')
    ));

    if (false === $results)
    {
      throw new QubitApiException;
    }

    $aipCreations = array();

    foreach ($results as $item)
    {
      $date = new DateTime($item->created_at);
      $timezone = new DateTimeZone('UTC');
      $createdAt = $date->setTimezone($timezone)->format('Y-m-d');
      $thumbnailPath = null;

      // Try to use local thumbnail first
      if (isset($item->thumbnail_path) && isset($item->thumbnail_name))
      {
        $thumbnailPath = $item->thumbnail_path.$item->thumbnail_name;
      }
      // Or use the master
      elseif (isset($item->master_path))
      {
        // Only use the path for external URIs
        $thumbnailPath = $item->master_path;

        // Otherwise, combine it with the name
        if ($item->master_usage_id != QubitTerm::EXTERNAL_URI_ID)
        {
          $thumbnailPath .= $item->master_name;
        }
      }

      array_push($aipCreations, array(
        'id' => $item->id,
        'level_of_description_id' => $item->level_of_description_id,
        'artwork_title' => $item->title,
        'aip_title' => $item->filename,
        'aip_uuid' => $item->uuid,
        'size_on_disk' => $item->size_on_disk,
        'thumbnail_path' => $thumbnailPath,
        'created_at' => $createdAt
      ));
    }

    return
      array(
        'results' => $aipCreations
      );
  }
}
