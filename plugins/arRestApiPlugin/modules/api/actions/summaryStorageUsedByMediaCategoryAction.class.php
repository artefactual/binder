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

class ApiSummaryStorageUsedByMediaCategoryAction extends QubitApiAction
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

    // Create nested aggregation to get total size by media category
    $agg = $this->buildEsAgg('Terms', 'media_type', 'metsData.format.name');
    $agg->addAggregation($this->buildEsAgg('Sum', 'size', 'metsData.size'));
    $query->addAggregation($agg);

    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitInformationObject')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $agg = $resultSet->getAggregation('media_type');
    $results = array();

    foreach($agg['buckets'] as $bucket)
    {
      $results[] = array(
        'media_type' => $bucket['key'],
        'total' => $bucket['size']['value']
      );
    }

    return $results;
  }
}
