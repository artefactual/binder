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

class ApiSummaryMediaFilesizeByYearAction extends QubitApiAction
{
  protected function get($request)
  {
    return array('results' => $this->getResults());
  }

  protected function getResults()
  {
    // Create query objects
    $query = new \Elastica\Query;
    $queryBool = new \Elastica\Query\BoolQuery;

    // Get all information objects
    $queryBool->addMust(new \Elastica\Query\MatchAll);

    // Assign query
    $query->setQuery($queryBool);

    // We don't need details, just facet results
    $query->setLimit(0);

    // Use a term stats facet to calculate total bytes used per media category
    $facetName = 'collection_year_file_stats';
    $facetOptions = array('valueField' => 'sizeOnDisk', 'setSize' => 1000);
    $this->facetEsQuery('TermsStats', $facetName, 'partOf.year_collected', $query, $facetOptions);

    // Return empty results if search fails
    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitAip')->search($query);
    }
    catch (Exception $e)
    {
      return array();
    }

    $facets = $resultSet->getFacets();

    foreach($facets[$facetName]['terms'] as $index => $term)
    {
      // Take note of average
      $average = $term['mean'];
      $facets[$facetName]['terms'][$index]['average'] = $average;

      // Convert millisecond timestamp to human-readable
      $facets[$facetName]['terms'][$index]['year'] = intval($term['term']);

      // Strip out extra data
      foreach(array('count', 'total_count', 'min', 'max', 'mean', 'term', 'total') as $element)
      {
        unset($facets[$facetName]['terms'][$index][$element]);
      }
    }

    // Sort by year
    function compare_year($a, $b)
    {
      return $a['year'] > $b['year'];
    }

    usort($facets[$facetName]['terms'], 'compare_year');

    // Add missing years: ElasticSearch 0.9 facets
    // don't include intervals without data, it can be solved
    // using aggregations in Elasticsearch 1.x.
    $results = array();
    foreach($facets[$facetName]['terms'] as $result)
    {
      if (isset($previousResult))
      {
        // Add missing years in between
        while ($previousResult['year'] + 1 < $result['year'])
        {
          $missingResult = array();
          $missingResult['year'] = $previousResult['year'] + 1;
          $missingResult['average'] = 0;

          $results[] = $missingResult;
          $previousResult = $missingResult;
        }
      }

      $results[] = $result;
      $previousResult = $result;
    }

    $currentYear = (integer)substr(date('Y-m-d'), 0, 4);

    // Add missing years at the end
    while ($currentYear - $previousResult['year'] > 0)
    {
      $missingResult = array();
      $missingResult['year'] = $previousResult['year'] + 1;
      $missingResult['average'] = 0;

      $results[] = $missingResult;
      $previousResult = $missingResult;
    }

    return $results;
  }
}
