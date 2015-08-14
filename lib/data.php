<?php

/**
 * ownCloud - Activity App
 *
 * @author Frank Karlitschek
 * @copyright 2013 Frank Karlitschek frank@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Activity;

use OCP\Activity\IExtension;
use OCP\Activity\IManager;
use OCP\DB;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User;
use OCP\Util;

/**
 * @brief Class for managing the data in the activities
 */
class Data {
	/** @var IManager */
	protected $activityManager;

	/** @var IDBConnection */
	protected $connection;

	/** @var IUserSession */
	protected $userSession;

	/**
	 * @param IManager $activityManager
	 * @param IDBConnection $connection
	 * @param IUserSession $userSession
	 */
	public function __construct(IManager $activityManager, IDBConnection $connection, IUserSession $userSession) {
		$this->activityManager = $activityManager;
		$this->connection = $connection;
		$this->userSession = $userSession;
	}

	protected $notificationTypes = array();

	/**
	 * @param \OCP\IL10N $l
	 * @return array Array "stringID of the type" => "translated string description for the setting"
	 * 				or Array "stringID of the type" => [
	 * 					'desc' => "translated string description for the setting"
	 * 					'methods' => [\OCP\Activity\IExtension::METHOD_*],
	 * 				]
	 */
	public function getNotificationTypes(\OCP\IL10N $l) {
		if (isset($this->notificationTypes[$l->getLanguageCode()])) {
			return $this->notificationTypes[$l->getLanguageCode()];
		}

		// Allow apps to add new notification types
		$notificationTypes = $this->activityManager->getNotificationTypes($l->getLanguageCode());
		$this->notificationTypes[$l->getLanguageCode()] = $notificationTypes;
		return $notificationTypes;
	}

	/**
	 * Send an event into the activity stream
	 *
	 * @param string $app The app where this event is associated with
	 * @param string $subject A short description of the event
	 * @param array  $subjectParams Array with parameters that are filled in the subject
	 * @param string $message A longer description of the event
	 * @param array  $messageParams Array with parameters that are filled in the message
	 * @param string $file The file including path where this event is associated with. (optional)
	 * @param string $link A link where this event is associated with (optional)
	 * @param string $affectedUser If empty the current user will be used
	 * @param string $type Type of the notification
	 * @param int    $prio Priority of the notification
	 * @param string $objectType Object type can be used to filter the activities later
	 * @param int    $objectId Object id can be used to filter the activities later
	 * @return bool
	 */
	public static function send($app, $subject, array $subjectParams, $message, array $messageParams, $file, $link, $affectedUser, $type, $prio, $objectType = '', $objectId = 0) {
		$timestamp = time();

		$user = \OC::$server->getUserSession()->getUser();
		if ($user instanceof IUser) {
			$user = $user->getUID();
		} else {
			// Public page or incognito mode
			$user = '';
		}

		if ($affectedUser === '' && $user === '') {
			return false;
		} else if ($affectedUser === '') {
			$affectedUser = $user;
		}

		// store in DB
		$queryBuilder = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$queryBuilder->insert('activity')
			->values([
				'app' => $queryBuilder->createParameter('app'),
				'subject' => $queryBuilder->createParameter('subject'),
				'subjectparams' => $queryBuilder->createParameter('subjectparams'),
				'message' => $queryBuilder->createParameter('message'),
				'messageparams' => $queryBuilder->createParameter('messageparams'),
				'file' => $queryBuilder->createParameter('file'),
				'link' => $queryBuilder->createParameter('link'),
				'user' => $queryBuilder->createParameter('user'),
				'affecteduser' => $queryBuilder->createParameter('affecteduser'),
				'timestamp' => $queryBuilder->createParameter('timestamp'),
				'priority' => $queryBuilder->createParameter('priority'),
				'type' => $queryBuilder->createParameter('type'),
				'object_type' => $queryBuilder->createParameter('object_type'),
				'object_id' => $queryBuilder->createParameter('object_id'),
			])
			->setParameters([
				'app' => $app,
				'subject' => $subject,
				'subjectparams' => json_encode($subjectParams),
				'message' => $message,
				'messageparams' => json_encode($messageParams),
				'file' => $file,
				'link' => $link,
				'user' => $user,
				'affecteduser' => $affectedUser,
				'timestamp' => (int) $timestamp,
				'priority' => (int) $prio,
				'type' => $type,
				'object_type' => $objectType,
				'object_id' => (int) $objectId,
			])
			->execute();

		return true;
	}

	/**
	 * @brief Send an event into the activity stream
	 *
	 * @param string $app The app where this event is associated with
	 * @param string $subject A short description of the event
	 * @param array  $subjectParams Array of parameters that are filled in the placeholders
	 * @param string $affectedUser Name of the user we are sending the activity to
	 * @param string $type Type of notification
	 * @param int $latestSendTime Activity time() + batch setting of $affectedUser
	 * @return bool
	 */
	public static function storeMail($app, $subject, array $subjectParams, $affectedUser, $type, $latestSendTime) {
		$timestamp = time();

		// store in DB
		$query = DB::prepare('INSERT INTO `*PREFIX*activity_mq` '
			. ' (`amq_appid`, `amq_subject`, `amq_subjectparams`, `amq_affecteduser`, `amq_timestamp`, `amq_type`, `amq_latest_send`) '
			. ' VALUES(?, ?, ?, ?, ?, ?, ?)');
		$query->execute(array(
			$app,
			$subject,
			json_encode($subjectParams),
			$affectedUser,
			$timestamp,
			$type,
			$latestSendTime,
		));

		return true;
	}

	/**
	 * @brief Read a list of events from the activity stream
	 * @param GroupHelper $groupHelper Allows activities to be grouped
	 * @param UserSettings $userSettings Gets the settings of the user
	 * @param int $start The start entry
	 * @param int $count The number of statements to read
	 * @param string $filter Filter the activities
	 * @param string $user User for whom we display the stream
	 * @param string $objecttype
	 * @param int $objectid
	 * @return array
	 */
	public function read(GroupHelper $groupHelper, UserSettings $userSettings, $start, $count, $filter = 'all', $user = '', $objecttype = '', $objectid = 0) {
		// get current user
		if ($user === '') {
			$user = $this->userSession->getUser();
			if ($user instanceof IUser) {
				$user = $user->getUID();
			} else {
				// No user given and not logged in => no activities
				return [];
			}
		}
		$groupHelper->setUser($user);

		$enabledNotifications = $userSettings->getNotificationTypes($user, 'stream');
		$enabledNotifications = $this->activityManager->filterNotificationTypes($enabledNotifications, $filter);
		$parameters = array_unique($enabledNotifications);

		// We don't want to display any activities
		if (empty($parameters)) {
			return array();
		}

		$placeholders = implode(',', array_fill(0, sizeof($parameters), '?'));
		$limitActivities = " AND `type` IN (" . $placeholders . ")";
		array_unshift($parameters, $user);

		if ($filter === 'self') {
			$limitActivities .= ' AND `user` = ?';
			$parameters[] = $user;
		} else if ($filter === 'by' || $filter === 'all' && !$userSettings->getUserSetting($user, 'setting', 'self')) {
			$limitActivities .= ' AND `user` <> ?';
			$parameters[] = $user;
		} else if ($filter === 'filter') {
			if (!$userSettings->getUserSetting($user, 'setting', 'self')) {
				$limitActivities .= ' AND `user` <> ?';
				$parameters[] = $user;
			}
			$limitActivities .= ' AND `object_type` = ?';
			$parameters[] = $objecttype;
			$limitActivities .= ' AND `object_id` = ?';
			$parameters[] = $objectid;
		}

		list($condition, $params) = $this->activityManager->getQueryForFilter($filter);
		if (!is_null($condition)) {
			$limitActivities .= ' ';
			$limitActivities .= $condition;
			if (is_array($params)) {
				$parameters = array_merge($parameters, $params);
			}
		}

		return $this->getActivities($count, $start, $limitActivities, $parameters, $groupHelper);
	}

	/**
	 * Process the result and return the activities
	 *
	 * @param int $count
	 * @param int $start
	 * @param string $limitActivities
	 * @param array $parameters
	 * @param \OCA\Activity\GroupHelper $groupHelper
	 * @return array
	 */
	protected function getActivities($count, $start, $limitActivities, $parameters, GroupHelper $groupHelper) {
		$query = $this->connection->prepare(
			'SELECT * '
			. ' FROM `*PREFIX*activity` '
			. ' WHERE `affecteduser` = ? ' . $limitActivities
			. ' ORDER BY `timestamp` DESC',
			$count, $start);
		$query->execute($parameters);

		while ($row = $query->fetch()) {
			$groupHelper->addActivity($row);
		}
		$query->closeCursor();

		return $groupHelper->getActivities();
	}

	/**
	 * Verify that the filter is valid
	 *
	 * @param string $filterValue
	 * @return string
	 */
	public function validateFilter($filterValue) {
		if (!isset($filterValue)) {
			return 'all';
		}

		switch ($filterValue) {
			case 'by':
			case 'self':
			case 'all':
			case 'filter':
				return $filterValue;
			default:
				if ($this->activityManager->isFilterValid($filterValue)) {
					return $filterValue;
				}
				return 'all';
		}
	}

	/**
	 * Delete old events
	 *
	 * @param int $expireDays Minimum 1 day
	 * @return null
	 */
	public function expire($expireDays = 365) {
		$ttl = (60 * 60 * 24 * max(1, $expireDays));

		$timelimit = time() - $ttl;
		$this->deleteActivities(array(
			'timestamp' => array($timelimit, '<'),
		));
	}

	/**
	 * Delete activities that match certain conditions
	 *
	 * @param array $conditions Array with conditions that have to be met
	 *                      'field' => 'value'  => `field` = 'value'
	 *    'field' => array('value', 'operator') => `field` operator 'value'
	 * @return null
	 */
	public function deleteActivities($conditions) {
		$sqlWhere = '';
		$sqlParameters = $sqlWhereList = array();
		foreach ($conditions as $column => $comparison) {
			$sqlWhereList[] = " `$column` " . ((is_array($comparison) && isset($comparison[1])) ? $comparison[1] : '=') . ' ? ';
			$sqlParameters[] = (is_array($comparison)) ? $comparison[0] : $comparison;
		}

		if (!empty($sqlWhereList)) {
			$sqlWhere = ' WHERE ' . implode(' AND ', $sqlWhereList);
		}

		$query = DB::prepare(
			'DELETE FROM `*PREFIX*activity`' . $sqlWhere);
		$query->execute($sqlParameters);
	}
}
