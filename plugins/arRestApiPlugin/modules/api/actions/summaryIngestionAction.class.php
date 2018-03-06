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

class ApiSummaryIngestionAction extends QubitApiAction
{
  protected function get($request)
  {
    return array('results' => $this->getResults());
  }

  protected function getResults()
  {
    $query = new \Elastica\Query;
    $query->setQuery(new \Elastica\Query\MatchAll);

    // We don't need details, just aggregation results
    $query->setSize(0);

    // Add aggregation to the query to get total level of description types
    $query->addAggregation($this->buildEsAgg('Terms', 'level', 'levelOfDescriptionId'));

    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitInformationObject')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $levelOfDescriptionInfo = array(
      sfConfig::get('app_drmc_lod_artwork_record_id')               => 'Artwork',
      sfConfig::get('app_drmc_lod_supporting_technology_record_id') => 'Supporting technology',
      sfConfig::get('app_drmc_lod_component_id')                    => 'Component'
    );

    $agg = $resultSet->getAggregation('level');
    $results = array();

    foreach ($agg['buckets'] as $bucket)
    {
      $termId = $bucket['key'];
      if (isset($levelOfDescriptionInfo[$termId]))
      {
        $results[] = array(
          'total' => $bucket['doc_count'],
          'type' => $levelOfDescriptionInfo[$termId]
        );
      }
    }

    return $results;
  }
}
