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

class arRestApiPluginUtils
{
  /**
   * Convert dates into ISO 8601 and UTC, recommended format in REST APIs.
   * Integers are treated as timestamps. Strings will be parsed using strtotime().
   */
  public static function convertDate($date, array $options = array())
  {
    if (empty($date))
    {
      return;
    }

    if ($date instanceof DateTime)
    {
      $dt = $date;
    }
    else if (is_int($date))
    {
      $dt = new DateTime();
      $dt->setTimestamp($date);
    }
    else
    {
      $dt = new DateTime;

      /**
       * [ TEMPORARY HACK ]
       *
       * ElasticSearch uses UTC but we are storing local times.
       * So their ISO dates are using the Z suffix (stands for UTC). This hack
       * replaces Z with difference greenwich time in hours, e.g.:
       * 2014-06-27T11:02:52Z -> 2014-06-27T11:02:52-7000
       *
       * Once we update our ES documents so they contain UTC dates, we'll be
       * able to stop using this hack.
       *
      */
      if (substr($date, -1) === 'Z')
      {
        $e = new DateTime();
        $date = substr($date, 0, -1).$e->format('O');
      }

      $timestamp = strtotime($date);
      $dt->setTimestamp($timestamp);
    }

    $format = DateTime::ISO8601;
    $timezone = new DateTimeZone('UTC');

    return $dt->setTimezone($timezone)->format($format);
  }

  /**
   * Check if an AIP is pending recovery.
   */
  public static function aipIsPendingRecovery($aipId)
  {
    // Determine whether a recovery, for this AIP, is awaiting approval
    $criteria = new Criteria;

    $criteria->add(QubitFixityRecovery::AIP_ID, $aipId);
    $criteria->add(QubitFixityRecovery::TIME_COMPLETED, null, Criteria::ISNULL);

    return (null != QubitFixityRecovery::getOne($criteria));
  }

  /**
   * Get the most recent QubitFixityRecovery object associated with an AIP.
   */
  public static function getMostRecentAipRecoveryAttempt($aipId)
  {
    $criteria = new Criteria;

    $criteria->add(QubitFixityRecovery::AIP_ID, $aipId);
    $criteria->add(QubitFixityRecovery::TIME_COMPLETED, null, Criteria::ISNOTNULL);
    $criteria->addDescendingOrderByColumn(QubitFixityRecovery::ID);

    return QubitFixityRecovery::getOne($criteria);
  }

  /**
   * Determine whether a recovery resolves a reported failure.
   */
  public static function recoveryResolvesFailureReport($reportTimeStarted, $recovery)
  {
    if (null != $recovery && null != $recovery->timeCompleted)
    {
      // Convert report time and recovery time to timestamps
      $reportTimestamp = strtotime($reportTimeStarted);
      $recoveredTimestamp = strtotime($recovery->timeStarted);

      return $recovery->success && ($recoveredTimestamp > $reportTimestamp);
    }
  }
}
