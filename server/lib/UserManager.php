<?php

namespace LookupServer;

use LookupServer\Validator\Email;
use LookupServer\Validator\Twitter;
use LookupServer\Validator\Website;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class UserManager {

	/** @var \PDO */
	private $db;

	/** @var Email */
	private $emailValidator;

	/** @var  Website */
	private $websiteValidator;

	/** @var Twitter */
	private $twitterValidator;

	/** @var SignatureHandler */
	private $signatureHandler;

	/** @var int try max. 10 times to verify a account */
	private $maxVerifyTries = 10;

	/** @var  bool */
	private $globalScaleMode;

	/**
	 * UserManager constructor.
	 *
	 * @param \PDO $db
	 * @param Email $emailValidator
	 * @param Website $websiteValidator
	 * @param Twitter $twitterValidator
	 * @param SignatureHandler $signatureHandler
	 * @param bool globalScaleMode
	 */
	public function __construct(\PDO $db,
								Email $emailValidator,
								Website $websiteValidator,
								Twitter $twitterValidator,
								SignatureHandler $signatureHandler,
								$globalScaleMode) {
		$this->db = $db;
		$this->emailValidator = $emailValidator;
		$this->websiteValidator = $websiteValidator;
		$this->twitterValidator = $twitterValidator;
		$this->signatureHandler = $signatureHandler;
		$this->globalScaleMode = $globalScaleMode;
	}

	public function search(Request $request, Response $response) {
		$params = $request->getQueryParams();

		if (!isset($params['search']) || $params['search'] === '') {
			$response->withStatus(404);
			return $response;
		}

		$search = $params['search'];
		$searchCloudId = $params['exactCloudId'];

		if ($searchCloudId === '1') {
			$user = $this->getExactCloudId($search);
			$response->getBody()->write(json_encode($user));
			return $response;
		}

		if ($this->globalScaleMode === true) {
			// in a global scale setup we ignore the karma
			$users = $this->searchNoKarma();
		} else {
			$users = $this->searchKarma();
		}

		$response->getBody()->write(json_encode($users));
		return $response;
	}

	/**
	 * return all results with karma >= 1
	 *
	 * @param $search
	 * @return array
	 */
	private function searchKarma($search) {
		$stmt = $this->db->prepare('SELECT *
FROM (
	SELECT userId AS userId, SUM(valid) AS karma
	FROM `store`
	WHERE userId IN (
		SELECT DISTINCT userId
		FROM `store`
		WHERE v LIKE :search
	)
	GROUP BY userId
) AS tmp
WHERE karma > 0
ORDER BY karma
LIMIT 50');
		$search = '%' . $search . '%';
		$stmt->bindParam(':search', $search, \PDO::PARAM_STR);
		$stmt->execute();

		/*
		 * TODO: Better fuzzy search?
		 */

		$users = [];
		while($data = $stmt->fetch()) {
			$users[] = $this->getForUserId((int)$data['userId']);
		}
		$stmt->closeCursor();

		return $users;
	}


	/**
	 * return all results, ignoring the karma
	 *
	 * @param $search
	 * @return array
	 */
	private function searchNoKarma($search) {
		$stmt = $this->db->prepare('SELECT *
FROM `store`
	WHERE userId IN (
		SELECT DISTINCT userId
		FROM `store`
		WHERE v LIKE :search
	)
	GROUP BY userId
    LIMIT 50');
		$search = '%' . $search . '%';
		$stmt->bindParam(':search', $search, \PDO::PARAM_STR);
		$stmt->execute();

		/*
		 * TODO: Better fuzzy search?
		 */

		$users = [];
		while($data = $stmt->fetch()) {
			$users[] = $this->getForUserId((int)$data['userId']);
		}
		$stmt->closeCursor();

		return $users;
	}

	private function getExactCloudId($cloudId) {
		$stmt = $this->db->prepare('SELECT id FROM users WHERE federationId = :id');
		$stmt->bindParam(':id', $cloudId);
		$stmt->execute();
		$data = $stmt->fetch();

		if (!$data) {
			return [];
		}

		return $this->getForUserId((int)$data['id']);

	}

	private function getForUserId($userId) {
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->bindParam(':id', $userId, \PDO::PARAM_INT);
		$stmt->execute();
		$data = $stmt->fetch();
		$stmt->closeCursor();

		if (!$data) {
			return [];
		}

		$result = [
			'federationId' => $data['federationId']
		];

		$stmt = $this->db->prepare('SELECT * FROM store WHERE userId = :id');
		$stmt->bindParam(':id', $userId, \PDO::PARAM_INT);
		$stmt->execute();

		while($data = $stmt->fetch()) {
			$result[$data['k']] = [
				'value' => $data['v'],
				'verified' => $data['valid']
			];
		}

		$stmt->closeCursor();
		return $result;
	}

	/**
	 * @param string $cloudId
	 * @param string[] $data
	 * @param int $timestamp
	 */
	private function insert($cloudId, $data, $timestamp) {
		$stmt = $this->db->prepare('INSERT INTO users (federationId, timestamp) VALUES (:federationId, FROM_UNIXTIME(:timestamp))');
		$stmt->bindParam(':federationId', $cloudId, \PDO::PARAM_STR);
		$stmt->bindParam(':timestamp', $timestamp, \PDO::PARAM_INT);
		$stmt->execute();
		$id = $this->db->lastInsertId();
		$stmt->closeCursor();

		$fields = ['name', 'email', 'address', 'website', 'twitter', 'phone', 'twitter_signature', 'website_signature'];

		foreach ($fields as $field) {
			if (!isset($data[$field]) || $data[$field] === '') {
				continue;
			}

			$stmt = $this->db->prepare('INSERT INTO store (userId, k, v) VALUES (:userId, :k, :v)');
			$stmt->bindParam(':userId', $id, \PDO::PARAM_INT);
			$stmt->bindParam(':k', $field, \PDO::PARAM_STR);
			$stmt->bindParam(':v', $data[$field], \PDO::PARAM_STR);
			$stmt->execute();
			$storeId = $this->db->lastInsertId();
			$stmt->closeCursor();

			if ($field === 'email') {
				$this->emailValidator->emailUpdated($data[$field], $storeId);
			}
		}
	}

	/**
	 * @param int $id
	 * @param string[] $data
	 * @param int $timestamp
	 */
	private function update($id, $data, $timestamp) {
		$stmt = $this->db->prepare('UPDATE users SET timestamp = FROM_UNIXTIME(:timestamp) WHERE id = :id');
		$stmt->bindParam(':id', $id, \PDO::PARAM_STR);
		$stmt->bindParam(':timestamp', $timestamp, \PDO::PARAM_INT);
		$stmt->execute();
		$stmt->closeCursor();
		$fields = ['name', 'email', 'address', 'website', 'twitter', 'phone', 'twitter_signature', 'website_signature'];

		$stmt = $this->db->prepare('SELECT * FROM store WHERE userId = :userId');
		$stmt->bindParam(':userId', $id, \PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll();
		$stmt->closeCursor();
		foreach ($rows as $row) {
			$key = $row['k'];
			$value = $row['v'];
			if (($loc = array_search($key, $fields)) !== false) {
				unset($fields[$loc]);
			}

			if (!isset($data[$key]) || $data[$key] === '') {
				// key not present in new data so delete
				$stmt = $this->db->prepare('DELETE FROM store WHERE id = :id');
				$stmt->bindParam(':id', $row['id']);
				$stmt->execute();
				$stmt->closeCursor();
				// remove verification request if correspondig data was deleted
				$this->removeOpenVerificationRequestByStoreId($row['id']);
			} else {
				// Key present check if we need to update
				if ($data[$key] === $value) {
					$this->needToVerify($id, $row['id'], $data, $key);
					continue;
				}
				$stmt = $this->db->prepare('UPDATE store SET v = :v, valid = 0 WHERE id = :id');
				$stmt->bindParam(':id', $row['id']);
				$stmt->bindParam(':v', $data[$key]);
				$stmt->execute();
				$stmt->closeCursor();
				if ($key === 'email') {
					$this->emailValidator->emailUpdated($data[$key], $row['id']);
				}
				// remove verification request from old data
				$this->removeOpenVerificationRequestByStoreId($row['id']);
			}
		}

		//Check for new fields
		foreach ($fields as $field) {
			// Not set or empty field
			if (!isset($data[$field]) || $data[$field] === '') {
				continue;
			}

			// Insert
			$stmt = $this->db->prepare('INSERT INTO store (userId, k, v) VALUES (:userId, :k, :v)');
			$stmt->bindParam(':userId', $id, \PDO::PARAM_INT);
			$stmt->bindParam(':k', $field, \PDO::PARAM_STR);
			$stmt->bindParam(':v', $data[$field], \PDO::PARAM_STR);
			$stmt->execute();
			$storeId = $this->db->lastInsertId();
			$stmt->closeCursor();

			if ($field === 'email') {
				$this->emailValidator->emailUpdated($data[$field], $storeId);
			}
		}
	}

	private function needToVerify($userId, $storeId, $data, $key) {
		$stmt = $this->db->prepare('SELECT * FROM toVerify WHERE  storeId = :storeId');
		$stmt->bindParam(':storeId', $storeId, \PDO::PARAM_INT);
		$stmt->execute();
		$alreadyExists = $stmt->fetch();

		if ($alreadyExists === false && isset($data['verificationStatus'][$key]) && $data['verificationStatus'][$key] === '1') {
			$tries = 0;
			$stmt = $this->db->prepare('INSERT INTO toVerify (userId, storeId, property, location, tries) VALUES (:userId, :storeId, :property, :location, :tries)');
			$stmt->bindParam(':userId', $userId, \PDO::PARAM_INT);
			$stmt->bindParam(':storeId', $storeId, \PDO::PARAM_INT);
			$stmt->bindParam(':property', $key);
			$stmt->bindParam(':location', $data[$key]);
			$stmt->bindParam(':tries', $tries, \PDO::PARAM_INT);
			$stmt->execute();
			$stmt->closeCursor();
		}
	}

	public function register(Request $request, Response $response) {
		$body = json_decode($request->getBody(), true);

		if ($body === null || !isset($body['message']) || !isset($body['message']['data']) ||
			!isset($body['message']['data']['federationId']) || !isset($body['signature']) ||
			!isset($body['message']['timestamp'])) {
			$response->withStatus(400);
			return $response;
		}

		$cloudId = $body['message']['data']['federationId'];

		try {
			$verified = $this->signatureHandler->verify($cloudId, $body['message'], $body['signature']);
		}  catch(\Exception $e) {
			$response->withStatus(400);
			return $response;
		}

		if ($verified) {
			$result = $this->insertOrUpdate($cloudId, $body['message']['data'], $body['message']['timestamp']);
			if ($result === false) {
				$response->withStatus(403);
			}
		} else {
			// ERROR OUT
			$response->withStatus(403);
		}

		return $response;
	}

	public function delete(Request $request, Response $response) {
		$body = json_decode($request->getBody(), true);

		if ($body === null || !isset($body['message']) || !isset($body['message']['data']) ||
			!isset($body['message']['data']['federationId']) || !isset($body['signature']) ||
			!isset($body['message']['timestamp'])) {
			$response->withStatus(400);
			return $response;
		}

		$cloudId = $body['message']['data']['federationId'];

		try {
			$verified = $this->signatureHandler->verify($cloudId, $body['message'], $body['signature']);
		}  catch(\Exception $e) {
			$response->withStatus(400);
			return $response;
		}


		if ($verified) {
			$result = $this->deleteDBRecord($cloudId);
			if ($result === false) {
				$response->withStatus(404);
			}
		} else {
			// ERROR OUT
			$response->withStatus(403);
		}

		return $response;
	}

	public function verify(Request $request, Response $response) {
		$verificationRequests = $this->getOpenVerificationRequests();
		foreach ($verificationRequests as $verificationData) {
			$success = false;
			switch ($verificationData['property']) {
				case 'twitter':
					$userData = $this->getForUserId($verificationData['userId']);
					$success = $this->twitterValidator->verify($verificationData, $userData);
					break;
				case 'website':
					$userData = $this->getForUserId($verificationData['userId']);
					$success = $this->websiteValidator->verify($verificationData, $userData);
					break;
			}
			if ($success) {
				$this->updateVerificationStatus($verificationData['storeId']);
				$this->removeOpenVerificationRequest($verificationData['id']);
			} else {
				$this->incMaxTries($verificationData);
			}
		}
	}

	/**
	 * increase number of max tries to verify account data
	 *
	 * @param $verificationData
	 */
	private function incMaxTries($verificationData) {
		$tries = (int)$verificationData['tries'];
		$tries++;

		// max number of tries reached, remove verification request and return
		if ($tries > $this->maxVerifyTries) {
			$this->removeOpenVerificationRequest($verificationData['id']);
			return;
		}

		$stmt = $this->db->prepare('UPDATE toVerify SET tries = :tries WHERE id = :id');
		$stmt->bindParam('id', $verificationData['id']);
		$stmt->bindParam('tries', $tries);
		$stmt->execute();
		$stmt->closeCursor();
	}

	/**
	 * if data could be verified successfully we update the information in the store table
	 *
	 * @param $storeId
	 */
	private function updateVerificationStatus($storeId) {
		$stmt = $this->db->prepare('UPDATE store SET valid = 1 WHERE id = :storeId');
		$stmt->bindParam('storeId', $storeId);
		$stmt->execute();
		$stmt->closeCursor();
	}

	/**
	 * remove data from to verify table if verification was successful or max. number of tries reached.
	 *
	 * @param $id
	 */
	private function removeOpenVerificationRequest($id) {
		$stmt = $this->db->prepare('DELETE FROM toVerify WHERE id = :id');
		$stmt->bindParam(':id', $id);
		$stmt->execute();
		$stmt->closeCursor();
	}

	/**
	 * remove data from to verify table if the user data was removed or changed
	 *
	 * @param $storeId
	 */
	private function removeOpenVerificationRequestByStoreId($storeId) {
		$stmt = $this->db->prepare('DELETE FROM toVerify WHERE storeId = :storeId');
		$stmt->bindParam(':storeId', $storeId);
		$stmt->execute();
		$stmt->closeCursor();
	}


	/**
	 * get open verification Requests
	 *
	 * @return array
	 */
	private function getOpenVerificationRequests() {
		$stmt = $this->db->prepare('SELECT * FROM toVerify LIMIT 10');
		$stmt->execute();
		$result = $stmt->fetchAll();
		$stmt->closeCursor();
		return $result;
	}

	/**
	 * @param string $cloudId
	 * @param string[] $data
	 * @param int $timestamp
	 * @return bool
	 */
	private function insertOrUpdate($cloudId, $data, $timestamp) {
		$stmt = $this->db->prepare('SELECT * FROM users WHERE federationId = :federationId');
		$stmt->bindParam(':federationId', $cloudId);
		$stmt->execute();
		$row = $stmt->fetch();
		$stmt->closeCursor();

		// If we can't find the user
		if ($row === false) {
			// INSERT
			$this->insert($cloudId, $data, $timestamp);
		} else {
			// User found. Check if it is not a replay from an old
			if ($timestamp <= (int)$row['timestamp']) {
				return false;
			}

			// UPDATE
			$this->update($row['id'], $data, $timestamp);
		}

		return true;
	}

	/**
	 * Delete all personal data. We keep the basic user entry with the
	 * federated cloud ID in order to propagate the changes
	 *
	 * @param string $cloudId
	 * @return bool
	 */
	private function deleteDBRecord($cloudId) {

		$stmt = $this->db->prepare('SELECT * FROM users WHERE federationId = :federationId');
		$stmt->bindParam(':federationId', $cloudId);
		$stmt->execute();
		$row = $stmt->fetch();
		$stmt->closeCursor();

		// If we can't find the user
		if ($row === false) {
			return false;
		}

		// delete user data
		$stmt = $this->db->prepare('DELETE FROM store WHERE userId = :userId');
		$stmt->bindParam(':userId', $row['id']);
		$stmt->execute();
		$stmt->closeCursor();

		return true;
	}
}
