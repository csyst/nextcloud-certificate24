<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, struktur AG.
 *
 * @author Joachim Bauch <bauch@struktur.de>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Certificate24\Controller;

use GuzzleHttp\Exception\ConnectException;
use OCA\Certificate24\AppInfo\Application;
use OCA\Certificate24\Client;
use OCA\Certificate24\Config;
use OCA\Certificate24\Events\SignEvent;
use OCA\Certificate24\Mails;
use OCA\Certificate24\Manager;
use OCA\Certificate24\Metadata;
use OCA\Certificate24\Requests;
use OCA\Certificate24\Tokens;
use OCA\Certificate24\Validator;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Image;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

if (!function_exists(__NAMESPACE__ . '\str_to_stream')) {
	function str_to_stream(string $string) {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $string);
		rewind($stream);
		return $stream;
	}
}

class ApiController extends OCSController {
	public const PDF_MIME_TYPES = [
		'application/pdf',
	];

	public const MAX_SIGN_OPTIONS_SIZE = 8 * 1024;
	public const MAX_IMAGE_SIZE = 1024 * 1024;

	private LoggerInterface $logger;
	private IAppManager $appManager;
	private IUserManager $userManager;
	private IUserSession $userSession;
	private IRootFolder $root;
	private ISearch $search;
	private IMailer $mailer;
	private IEventDispatcher $dispatcher;
	private IFactory $l10nFactory;
	private IL10N $l10n;
	private IConfig $systemConfig;
	private Client $client;
	private Config $config;
	private Requests $requests;
	private Metadata $metadata;
	private Validator $validator;
	private Mails $mails;
	private Manager $manager;
	private Tokens $tokens;

	public function __construct(string $appName,
		IRequest $request,
		LoggerInterface $logger,
		IAppManager $appManager,
		IUserManager $userManager,
		IUserSession $userSession,
		IRootFolder $root,
		ISearch $search,
		IMailer $mailer,
		IEventDispatcher $dispatcher,
		IFactory $l10nFactory,
		IL10N $l10n,
		IConfig $systemConfig,
		Client $client,
		Config $config,
		Requests $requests,
		Metadata $metadata,
		Validator $validator,
		Mails $mails,
		Manager $manager,
		Tokens $tokens) {
		parent::__construct($appName, $request);
		$this->logger = $logger;
		$this->appManager = $appManager;
		$this->userManager = $userManager;
		$this->userSession = $userSession;
		$this->root = $root;
		$this->search = $search;
		$this->mailer = $mailer;
		$this->dispatcher = $dispatcher;
		$this->l10nFactory = $l10nFactory;
		$this->l10n = $l10n;
		$this->systemConfig = $systemConfig;
		$this->client = $client;
		$this->config = $config;
		$this->requests = $requests;
		$this->metadata = $metadata;
		$this->validator = $validator;
		$this->mails = $mails;
		$this->manager = $manager;
		$this->tokens = $tokens;
	}

	private function formatDateTime($dt) {
		if (!$dt) {
			return null;
		}
		if (is_string($dt)) {
			$dt = $this->requests->parseDateTime($dt);
			if (!$dt) {
				return null;
			}
		}
		return $dt->format(\DateTime::RFC3339);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $file_id
	 * @param ?array $recipients
	 * @param ?array $options
	 * @param ?array $metadata
	 * @return DataResponse
	 */
	public function shareFile(int $file_id, string $recipient = '', string $recipient_type = '', ?array $recipients = null, ?array $options = null, ?array $metadata = null): DataResponse {
		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		if (empty($recipients)) {
			if ($recipient && $recipient_type) {
				// Deprecated compatibility settings.
				$recipients = [
					[
						'type' => $recipient_type,
						'value' => $recipient,
					],
				];
			}

			if (empty($recipients)) {
				return new DataResponse([
					'error' => 'no_recipients',
				], Http::STATUS_BAD_REQUEST);
			}
		}

		$users = [];
		$emails = [];
		$required = [];
		$idx = 0;
		foreach ($recipients as &$r) {
			$recipient_type = $r['type'] ?? null;
			$recipient = $r['value'] ?? null;
			$required[$idx++] = true;
			switch ($recipient_type) {
				case 'email':
					if (isset($emails[$recipient])) {
						return new DataResponse([
							'error' => 'duplicate_email',
						], Http::STATUS_BAD_REQUEST);
					} elseif (!$recipient || !$this->mailer->validateMailAddress($recipient)) {
						return new DataResponse([
							'error' => 'invalid_email',
						], Http::STATUS_BAD_REQUEST);
					}
					if (isset($r['display_name']) && $r['display_name'] === $r['value']) {
						unset($r['display_name']);
					}
					$emails[$recipient] = true;
					break;
				case 'user':
					if (isset($users[$recipient])) {
						return new DataResponse([
							'error' => 'duplicate_user',
						], Http::STATUS_BAD_REQUEST);
					}

					$recipientUser = $recipient ? $this->userManager->get($recipient) : null;
					if (!$recipientUser) {
						return new DataResponse([
							'error' => 'unknown_user',
						], Http::STATUS_NOT_FOUND);
					}
					if ($recipientUser->getDisplayName() !== $recipient) {
						$r['display_name'] = $recipientUser->getDisplayName();
					}
					$users[$recipient] = $recipientUser;
					break;
				default:
					return new DataResponse([
						'error' => 'invalid_recipient_type',
					], Http::STATUS_BAD_REQUEST);
			}
		}

		$metadata = !empty($metadata) ? $metadata : [];

		$error = $this->validator->validateShareMetadata($metadata);
		if ($error) {
			return new DataResponse([
				'error' => 'invalid_metadata',
				'details' => $error
			], Http::STATUS_BAD_REQUEST);
		}

		$fields = $metadata['signature_fields'] ?? null;
		if (empty($fields)) {
			return new DataResponse([
				'error' => 'invalid_metadata',
				'details' => ['no signature fields found'],
			], Http::STATUS_BAD_REQUEST);
		}

		if (count($recipients) > 1) {
			foreach ($fields as $field) {
				$idx = $field['recipient_idx'] ?? null;
				if ($idx === null) {
					return new DataResponse([
						'error' => 'invalid_metadata',
						'details' => ['field has no recipient_idx'],
					], Http::STATUS_BAD_REQUEST);
				} elseif ($idx < 0 || $idx >= count($recipients)) {
					return new DataResponse([
						'error' => 'invalid_metadata',
						'details' => ['recipient_idx is out of bounds'],
					], Http::STATUS_BAD_REQUEST);
				}

				unset($required[$idx]);
			}

			if (!empty($required)) {
				return new DataResponse([
					'error' => 'invalid_metadata',
					'details' => ['recipient has no field assigned'],
				], Http::STATUS_BAD_REQUEST);
			}
		}

		$user = $this->userSession->getUser();
		if ($user) {
			$files = $this->root->getUserFolder($user->getUID())->getById($file_id);
		} else {
			$files = $this->root->getById($file_id);
		}

		if (empty($files)) {
			return new DataResponse([
				'error' => 'unknown_file',
			], Http::STATUS_NOT_FOUND);
		}

		$file = $files[0];
		if (!$file->isReadable() || !$file->isUpdateable()) {
			return new DataResponse([
				'error' => 'error_accessing_file',
			], Http::STATUS_FORBIDDEN);
		}

		$mime = $file->getMimeType();
		if ($mime) {
			$mime = strtolower($mime);
		}
		if (!in_array($mime, self::PDF_MIME_TYPES)) {
			return new DataResponse([
				'error' => 'invalid_filetype',
			], Http::STATUS_BAD_REQUEST);
		}

		if (empty($options)) {
			$options = null;
		}

		$error = $this->validator->validateShareOptions($options);
		if ($error) {
			return new DataResponse([
				'error' => 'invalid_options',
				'details' => $error
			], Http::STATUS_BAD_REQUEST);
		}

		$lang = $this->l10nFactory->getUserLanguage($user);
		if (empty($lang)) {
			$lang = $this->l10n->getLanguageCode();
		}
		$metadata['sender'] = [
			'language' => $lang,
		];
		$timezone = $this->systemConfig->getUserValue($user->getUID(), 'core', 'timezone', null);
		if (!empty($timezone)) {
			$metadata['sender']['timezone'] = $timezone;
		}

		$server = $this->config->getApiServer();
		try {
			$data = $this->client->shareFile($file, $recipients, $metadata, $account, $server);
		} catch (ConnectException $e) {
			$this->logger->error('Error connecting to ' . $server, [
				'exception' => $e,
			]);
			return new DataResponse(['error' => 'error_connecting'], Http::STATUS_BAD_GATEWAY);
		} catch (\Exception $e) {
			$this->logger->error('Error sending request to ' . $server, [
				'exception' => $e,
			]);
			return new DataResponse(['error' => $e->getCode()], Http::STATUS_BAD_GATEWAY);
		}

		$response_file_id = $data['file_id'] ?? '';
		if (empty($response_file_id)) {
			return new DataResponse(['error' => 'invalid_response'], Http::STATUS_BAD_GATEWAY);
		}

		$recipients = $data['recipients'] ?? $recipients;
		$response_signature_result_id = $data['signature_id'] ?? null;
		$id = $this->requests->storeRequest($file, $user, $recipients, $options, $metadata, $account, $server, $response_file_id, $response_signature_result_id);

		$this->metadata->storeMetadata($user, $file, $metadata);

		foreach ($recipients as $recipient) {
			$recipient_type = $recipient['type'];
			if ($recipient_type !== 'email') {
				continue;
			}

			if (!isset($recipient['c24_signature_id'])) {
				$recipient['c24_signature_id'] = $recipient['public_id'];
			}

			$this->mails->sendRequestMail($id, $user, $file, $recipient, $server);
		}

		return new DataResponse(['request_id' => $id], Http::STATUS_CREATED);
	}

	private function formatRecipients(array $recipients): array {
		$result = [];
		foreach ($recipients as $recipient) {
			$type = $recipient['type'];
			$value = $recipient['value'];
			$entry = [
				'type' => $type,
				'value' => $value,
			];
			$signed = $recipient['signed'];
			if ($signed) {
				$entry['signed'] = $this->formatDateTime($signed);
			}
			if ($type === 'user') {
				$entry['display_name'] = $this->userManager->getDisplayName($value);
			} elseif (isset($recipient['display_name']) && $recipient['display_name']) {
				$entry['display_name'] = $recipient['display_name'];
			}
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param bool $include_signed
	 * @return DataResponse
	 */
	public function getRequests(?bool $include_signed = false): DataResponse {
		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		$user = $this->userSession->getUser();
		$requests = $this->requests->getOwnRequests($user, $include_signed);

		$root = $this->root->getUserFolder($user->getUID());
		$response = [];
		foreach ($requests as $request) {
			$files = $root->getById($request['file_id']);
			if (empty($files)) {
				// Should not happen, requests are deleted when files are.
				continue;
			}

			$file = $files[0];
			$mime = $file->getMimeType();
			if ($mime) {
				$mime = strtolower($mime);
			}

			$r = [
				'request_id' => $request['id'],
				'created' => $this->formatDateTime($request['created']),
				'file_id' => $request['file_id'],
				'filename' => $file->getName(),
				'mimetype' => $mime,
				'download_url' => $this->client->getOriginalUrl($request['c24_file_id'], $account, $request['c24_server']),
				'recipients' => $this->formatRecipients($request['recipients']),
				'metadata' => $request['metadata'],
			];
			if ($include_signed) {
				$allSigned = true;
				foreach ($request['recipients'] as $recipient) {
					if (!isset($recipient['signed'])) {
						$allSigned = false;
						continue;
					}

					$signed = $this->formatDateTime($recipient['signed']);
					if (!isset($r['signed']) || $signed > $r['signed']) {
						$r['signed'] = $signed;
					}
					if (!isset($r['signed_url'])) {
						$r['signed_url'] = $this->client->getSignedUrl($request['c24_file_id'], $account, $request['c24_server']);
					}
				}
				if (!$allSigned) {
					unset($r['signed']);
					unset($r['signed_url']);
				} elseif ($request['c24_signature_result_id']) {
					$r['details_url'] = $this->client->getDetailsUrl($request['c24_signature_result_id'], $request['c24_server']);
				}
			}
			$response[] = $r;
		}
		return new DataResponse($response);
	}

	/**
	 * @returns array|null
	 */
	private function filterMetadata(array $request, string $type, string $value) {
		$metadata = $request['metadata'];
		if (empty($metadata) || !isset($metadata['signature_fields'])) {
			return $metadata;
		}

		$recipients = $request['recipients'];
		if (count($recipients) <= 1) {
			return $metadata;
		}

		$idx = -1;
		$found = -1;
		foreach ($recipients as $recipient) {
			$idx++;
			if ($recipient['type'] === $type && $recipient['value'] === $value) {
				$found = $idx;
				break;
			}
		}

		if ($found === -1) {
			return $metadata;
		}

		$fields = [];
		foreach ($metadata['signature_fields'] as $field) {
			if (!isset($field['recipient_idx']) || $field['recipient_idx'] !== $found) {
				continue;
			}

			$fields[] = $field;
		}

		$filtered = $metadata;
		$filtered['signature_fields'] = $fields;
		return $filtered;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param bool $include_signed
	 * @return DataResponse
	 */
	public function getIncomingRequests(?bool $include_signed = false): DataResponse {
		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		$user = $this->userSession->getUser();
		$requests = $this->requests->getIncomingRequests($user, $include_signed);

		$response = [];
		foreach ($requests as $request) {
			$owner = $this->userManager->get($request['user_id']);
			if (!$owner) {
				// Should not happen, owned requests are deleted when users are.
				continue;
			}

			$files = $this->root->getUserFolder($owner->getUID())->getById($request['file_id']);
			if (empty($files)) {
				// Should not happen, requests are deleted when files are.
				continue;
			}

			$file = $files[0];
			$mime = $file->getMimeType();
			if ($mime) {
				$mime = strtolower($mime);
			}
			$metadata = $this->filterMetadata($request, 'user', $user->getUID());
			$r = [
				'request_id' => $request['id'],
				'created' => $this->formatDateTime($request['created']),
				'user_id' => $request['user_id'],
				'display_name' => $owner ? $owner->getDisplayName() : null,
				'filename' => $file->getName(),
				'mimetype' => $mime,
				'download_url' => $this->client->getSourceUrl($request['c24_file_id'], $account, $request['c24_server']),
				'metadata' => $metadata,
			];
			if ($include_signed) {
				$allSigned = true;
				foreach ($request['recipients'] as $recipient) {
					if (!$recipient['signed']) {
						$allSigned = false;
						continue;
					}

					$signed = $this->formatDateTime($recipient['signed']);
					if (!isset($r['signed']) || $signed > $r['signed']) {
						$r['signed'] = $signed;
					}
					if (!isset($r['signed_url'])) {
						$r['signed_url'] = $this->client->getSignedUrl($request['c24_file_id'], $account, $request['c24_server']);
					}

					if ($recipient['type'] === 'user' && $recipient['value'] === $user->getUID()) {
						$r['own_signed'] = $signed;
					}
				}
				if (!$allSigned) {
					unset($r['signed']);
					unset($r['signed_url']);
				} elseif ($request['c24_signature_result_id']) {
					$r['details_url'] = $this->client->getDetailsUrl($request['c24_signature_result_id'], $request['c24_server']);
				}
			}
			$response[] = $r;
		}

		return new DataResponse($response);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $id
	 * @return DataResponse
	 */
	public function getRequest(string $id): DataResponse {
		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		$user = $this->userSession->getUser();
		$request = $this->requests->getOwnRequestById($user, $id);
		if (!$request) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$files = $this->root->getUserFolder($user->getUID())->getById($request['file_id']);
		if (empty($files)) {
			// Should not happen, requests are deleted when files are.
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$file = $files[0];
		$mime = $file->getMimeType();
		if ($mime) {
			$mime = strtolower($mime);
		}

		$response = [
			'request_id' => $request['id'],
			'created' => $this->formatDateTime($request['created']),
			'file_id' => $request['file_id'],
			'filename' => $file->getName(),
			'mimetype' => $mime,
			'download_url' => $this->client->getOriginalUrl($request['c24_file_id'], $account, $request['c24_server']),
			'recipients' => $this->formatRecipients($request['recipients']),
			'metadata' => $request['metadata'],
		];
		$allSigned = true;
		foreach ($request['recipients'] as $recipient) {
			if (!isset($recipient['signed'])) {
				$allSigned = false;
				continue;
			}

			$signed = $this->formatDateTime($recipient['signed']);
			if (!isset($response['signed']) || $signed > $response['signed']) {
				$response['signed'] = $signed;
			}
			if (!isset($response['signed_url'])) {
				$response['signed_url'] = $this->client->getSignedUrl($request['c24_file_id'], $account, $request['c24_server']);
			}
		}
		if (!$allSigned) {
			unset($response['signed']);
			unset($response['signed_url']);
		} elseif ($request['c24_signature_result_id']) {
			$response['details_url'] = $this->client->getDetailsUrl($request['c24_signature_result_id'], $request['c24_server']);
		}
		return new DataResponse($response);
	}

	/**
	 * @PublicPage
	 * @BruteForceProtection(action=certificate24_request)
	 *
	 * @param string $id
	 * @return DataResponse
	 */
	public function getIncomingRequest(string $id, ?string $email = null): DataResponse {
		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		$user = $this->userSession->getUser();
		if (!empty($email)) {
			$type = 'email';
			$value = $email;
		} elseif ($user) {
			$type = 'user';
			$value = $user->getUID();
		} else {
			return new DataResponse([
				'error' => 'unknown_recipient',
			], Http::STATUS_BAD_REQUEST);
		}

		$request = $this->requests->getRequestById($id);
		if (!$request) {
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		$found = false;
		foreach ($request['recipients'] as $recipient) {
			if ($recipient['type'] !== $type || $recipient['value'] !== $value) {
				continue;
			}

			$found = true;
			break;
		}

		if (!$found) {
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		return $this->returnIncomingRequest($request, $type, $value, $account);
	}

	/**
	 * @PublicPage
	 * @BruteForceProtection(action=certificate24_signature)
	 *
	 * @param string $id
	 * @return DataResponse
	 */
	public function getSignatureRequest(string $id): DataResponse {
		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		$request = $this->requests->getRequestByCertificate24SignatureId($id);
		if (!$request) {
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		$r = null;
		foreach ($request['recipients'] as $recipient) {
			if ($recipient['c24_signature_id'] === $id) {
				$r = $recipient;
				break;
			}
		}
		if (!$r) {
			// Should not happen, we queried the request based on the signature id.
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			return $response;
		}

		return $this->returnIncomingRequest($request, $r['type'], $r['value'], $account);
	}

	private function returnIncomingRequest(array $request, string $type, string $value, array $account) {
		$owner = $this->userManager->get($request['user_id']);
		if (!$owner) {
			// Should not happen, owned requests are deleted when users are.
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$files = $this->root->getUserFolder($owner->getUID())->getById($request['file_id']);
		if (empty($files)) {
			// Should not happen, requests are deleted when files are.
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$file = $files[0];
		$mime = $file->getMimeType();
		if ($mime) {
			$mime = strtolower($mime);
		}
		$metadata = $this->filterMetadata($request, $type, $value);
		$response = [
			'request_id' => $request['id'],
			'created' => $this->formatDateTime($request['created']),
			'user_id' => $request['user_id'],
			'display_name' => $owner ? $owner->getDisplayName() : null,
			'filename' => $file->getName(),
			'mimetype' => $mime,
			'download_url' => $this->client->getSourceUrl($request['c24_file_id'], $account, $request['c24_server']),
			'metadata' => $metadata,
		];
		$allSigned = true;
		foreach ($request['recipients'] as $recipient) {
			if (!$recipient['signed']) {
				$allSigned = false;
				continue;
			}

			$signed = $this->formatDateTime($recipient['signed']);
			if (!isset($response['signed']) || $signed > $response['signed']) {
				$response['signed'] = $signed;
			}
			if (!isset($response['signed_url'])) {
				$response['signed_url'] = $this->client->getSignedUrl($request['c24_file_id'], $account, $request['c24_server']);
			}

			if ($recipient['type'] === $type && $recipient['value'] === $value) {
				$response['own_signed'] = $signed;
			}
		}
		if (!$allSigned) {
			unset($response['signed']);
			unset($response['signed_url']);
		} elseif ($request['c24_signature_result_id']) {
			$response['details_url'] = $this->client->getDetailsUrl($request['c24_signature_result_id'], $request['c24_server']);
		}
		return new DataResponse($response);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $id
	 * @return DataResponse
	 */
	public function deleteRequest(string $id): DataResponse {
		$user = $this->userSession->getUser();
		$row = $this->requests->getRequestById($id);
		if (!$row || $row['user_id'] !== $user->getUID()) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		} elseif ($account['id'] !== $row['c24_account_id']) {
			return new DataResponse([
				'error' => 'invalid_account',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		try {
			$data = $this->client->deleteFile($row['c24_file_id'], $account, $row['c24_server']);
		} catch (ConnectException $e) {
			return new DataResponse([
				'error' => 'error_connecting'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getCode()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$status = $data['status'] ?? '';
		if ($status !== 'success') {
			return new DataResponse(['error' => 'INVALID_RESPONSE'], Http::STATUS_BAD_GATEWAY);
		}

		$this->requests->deleteRequestById($id);
		return new DataResponse([]);
	}

	/**
	 * @PublicPage
	 * @BruteForceProtection(action=certificate24_request)
	 *
	 * @param string $id
	 * @return DataResponse
	 */
	public function signRequest(string $id): DataResponse {
		$options = [];
		$optionsData = $this->request->getParam('options');
		if ($optionsData) {
			$options = json_decode($optionsData, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				return new DataResponse([
					'error' => 'invalid_options_format',
				], Http::STATUS_BAD_REQUEST);
			}
		}

		$user = $this->userSession->getUser();
		$email = $options['email'] ?? null;
		if ($email) {
			$type = 'email';
			$value = $email;
		} elseif ($user) {
			$type = 'user';
			$value = $user->getUID();
		} else {
			return new DataResponse([
				'error' => 'unknown_recipient',
			], Http::STATUS_BAD_REQUEST);
		}

		$row = $this->requests->getRequestById($id);
		if (!$row) {
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		$found = false;
		$recipient_idx = 0;
		foreach ($row['recipients'] as $recipient) {
			if ($recipient['type'] !== $type || $recipient['value'] !== $value) {
				$recipient_idx++;
				continue;
			}

			if ($recipient['signed']) {
				return new DataResponse([
					'error' => 'already_signed',
				], Http::STATUS_CONFLICT);
			}

			$found = true;
			break;
		}

		if (!$found) {
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		$account = $this->config->getAccount();
		if (!$account['id'] || !$account['secret']) {
			return new DataResponse([
				'error' => 'unconfigured',
			], Http::STATUS_PRECONDITION_FAILED);
		} elseif ($account['id'] !== $row['c24_account_id']) {
			return new DataResponse([
				'error' => 'invalid_account',
			], Http::STATUS_PRECONDITION_FAILED);
		}

		$multipart = [];

		$metadata = $row['metadata'] ?? [];
		$fields = $metadata['signature_fields'] ?? [];
		if (!empty($fields)) {
			$embed = $options['embed_user_signature'] ?? false;
			if ($user && $embed) {
				$imageFile = $this->config->getSignatureImage($user);
			} else {
				$imageFile = null;
			}

			$imageId = null;
			$checkRecipientIdx = count($row['recipients']) > 1;
			foreach ($fields as $field) {
				$fieldId = $field['id'];
				if ($checkRecipientIdx && isset($field['recipient_idx']) && $field['recipient_idx'] !== $recipient_idx) {
					continue;
				}

				$ref = $this->request->getParam($fieldId);
				if ($ref) {
					// Reference another image from the request.
					$multipart[] = [
						'name' => $fieldId,
						'contents' => $ref,
					];
					continue;
				}

				$image = $this->request->getUploadedFile($fieldId);
				if ($image) {
					if (!isset($image['error']) || is_array($image['error'])) {
						return new DataResponse([
							'error' => 'invalid_field',
							'field' => $fieldId,
						], Http::STATUS_BAD_REQUEST);
					}

					if ($image['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($image['tmp_name'])) {
						return new DataResponse([
							'error' => 'no_uploaded_file',
							'field' => $fieldId,
						], Http::STATUS_BAD_REQUEST);
					}

					if ($image['size'] > self::MAX_IMAGE_SIZE) {
						return new DataResponse([
							'error' => 'image_too_large',
							'field' => $fieldId,
						], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
					}

					$data = file_get_contents($image['tmp_name']);
					$img = new Image();
					if (!$img->loadFromData($data) || !$img->valid()) {
						return new DataResponse([
							'error' => 'error_loading_image',
							'field' => $fieldId,
						], Http::STATUS_BAD_REQUEST);
					}

					// Use uploaded image.
					$multipart[] = [
						'name' => $fieldId,
						'filename' => $fieldId,
						'contents' => $data,
						'headers' => [
							'Content-Type' => $img->mimeType(),
						],
					];
					continue;
				}

				if ($imageId) {
					// Reference configured personal signature image.
					$multipart[] = [
						'name' => $fieldId,
						'contents' => $imageId,
					];
				} elseif ($imageFile) {
					// Use configured personal signature image.
					$content = $imageFile->getContent();
					$mime = $imageFile->getMimetype();
					if (!$mime || $mime === 'application/octet-stream') {
						$mime = mime_content_type(str_to_stream($content));
						if (!$mime) {
							$mime = 'application/octet-stream';
						}
					}

					$imageId = $fieldId;
					$multipart[] = [
						'name' => $fieldId,
						'filename' => $fieldId,
						'contents' => $content,
						'headers' => [
							'Content-Type' => $mime,
						],
					];
				} else {
					// Required signature image not given.
					return new DataResponse([
						'error' => 'signature_images_missing',
					], Http::STATUS_PRECONDITION_FAILED);
				}
			};
		}

		$multipart[] = [
			'name' => 'options',
			'contents' => json_encode([
				'version' => '1.0',
				'signer' => [
					'type' => $type,
					'value' => $value,
				],
			]),
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		$clientInfo = [
			'clientip' => $this->request->getRemoteAddress(),
		];
		if (!empty($this->request->getHeader('User-Agent'))) {
			$clientInfo['useragent'] = $this->request->getHeader('User-Agent');
		}
		$multipart[] = [
			'name' => 'metadata',
			'contents' => json_encode([
				'client' => $clientInfo,
			]),
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		try {
			$data = $this->client->signFile($row['c24_file_id'], $multipart, $account, $row['c24_server']);
		} catch (ConnectException $e) {
			return new DataResponse([
				'error' => 'error_connecting'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Exception $e) {
			switch ($e->getCode()) {
				case Http::STATUS_CONFLICT:
					// Document was already signed. Signature information will be fetched by background job.
					return new DataResponse([
						'error' => 'already_signed',
					], Http::STATUS_CONFLICT);
				default:
					return new DataResponse(['error' => $e->getCode()], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		}

		$status = $data['status'] ?? '';
		if ($status !== 'success') {
			return new DataResponse(['error' => 'INVALID_RESPONSE'], Http::STATUS_BAD_GATEWAY);
		}

		$signed = $data['signed'] ?? null;
		if (is_string($signed)) {
			$signed = $this->requests->parseDateTime($signed);
		}
		if (!$signed) {
			$signed = new \DateTime();
		}

		$isLast = $this->requests->markRequestSignedById($id, $type, $value, $signed);

		$event = new SignEvent($id, $row, $type, $value, $signed, $user, $isLast);
		$this->dispatcher->dispatchTyped($event);

		if ($isLast) {
			$this->manager->saveSignedResult($row, $signed, $user, $account);
		}

		$response = [
			'request_id' => $id,
			'signed' => $this->formatDateTime($signed),
		];
		if ($isLast && $row['c24_signature_result_id']) {
			$response['details_url'] = $this->client->getDetailsUrl($row['c24_signature_result_id'], $row['c24_server']);
		}
		return new DataResponse($response);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $search
	 * @return DataResponse
	 */
	public function search(string $search = '', string $type = ''): DataResponse {
		$shareTypes = [];
		switch ($type) {
			case 'user':
				$shareTypes[] = IShare::TYPE_USER;
				break;
			case 'email':
				$shareTypes[] = IShare::TYPE_EMAIL;
				break;
			case 'group':
				$shareTypes[] = IShare::TYPE_GROUP;
				break;
		}
		if (empty($shareTypes)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$minLength = 3;
		if (strlen($search) < $minLength) {
			return new DataResponse([]);
		}

		$limit = 10;
		$offset = 0;
		$lookup = false;  // Don't use lookup server.
		[$result, $hasMoreResults] = $this->search->search($search, $shareTypes, $lookup, $limit, $offset);

		if ($type === 'group') {
			// Convert group to users
			foreach(){
				
			}
		}
		
		if ($type === 'user') {
			// Filter out users not allowed to use the app.
			$userCache = [];
			$filterFunc = function ($elem) use ($userCache) {
				$userId = $elem['value']['shareWith'];
				$user = $userCache[$userId] ?? null;
				if (!$user) {
					$user = $this->userManager->get($userId);
					if (!$user) {
						return true;
					}
					$userCache[$userId] = $user;
				}
				return $this->appManager->isEnabledForUser(Application::APP_ID, $user);
			};

			$totalResults = count($result['exact']['users'] ?? []) + count($result['users'] ?? []);
			if (isset($result['exact']['users'])) {
				$result['exact']['users'] = array_filter($result['exact']['users'], $filterFunc);
			}
			if (isset($result['users'])) {
				$result['users'] = array_filter($result['users'], $filterFunc);
			}
			$total = count($result['exact']['users'] ?? []) + count($result['users'] ?? []);
			while ($totalResults > 0 && $total < $limit && $hasMoreResults) {
				$offset += $limit;
				[$additional, $hasMoreResults] = $this->search->search($search, $shareTypes, $lookup, $limit, $offset);
				$totalResults = count($additional['exact']['users'] ?? []) + count($additional['users'] ?? []);
				if (!$totalResults) {
					break;
				}

				if (isset($additional['exact']['users'])) {
					$additional['exact']['users'] = array_filter($additional['exact']['users'], $filterFunc);
				}
				if (isset($additional['users'])) {
					$additional['users'] = array_filter($additional['users'], $filterFunc);
				}
				$result['exact']['users'] = array_merge($result['exact']['users'], $additional['exact']['users']);
				$result['users'] = array_merge($result['users'], $additional['users']);
				$total = count($result['exact']['users'] ?? []) + count($result['users'] ?? []);
			}
		}
		$response = new DataResponse($result);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $id
	 * @return DataResponse
	 */
	public function getFileMetadata(string $id): DataResponse {
		$user = $this->userSession->getUser();
		$files = $this->root->getUserFolder($user->getUID())->getById($id);

		if (empty($files)) {
			return new DataResponse([
				'error' => 'unknown_file',
			], Http::STATUS_NOT_FOUND);
		}

		$file = $files[0];
		if (!$file->isReadable() || !$file->isUpdateable()) {
			return new DataResponse([
				'error' => 'error_accessing_file',
			], Http::STATUS_FORBIDDEN);
		}

		$metadata = $this->metadata->getMetadata($user, $file);
		if ($metadata === null) {
			$metadata = new \stdClass();
		}
		return new DataResponse($metadata);
	}

	/**
	 * @PublicPage
	 * @BruteForceProtection(action=certificate24_file)
	 *
	 * @param string $id
	 * @param string $signature
	 * @return DataResponse
	 */
	public function notifySigned(string $id, string $signature) {
		$account = $this->config->getAccount();
		$token = $this->request->getHeader('X-Vinegar-Token');
		if (!$this->tokens->validateToken($token, $account, $signature, 'notify-signed')) {
			$response = new DataResponse([], Http::STATUS_FORBIDDEN);
			$response->throttle();
			return $response;
		}

		$request = $this->requests->getRequestByCertificate24FileId($id);
		if (!$request) {
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		$found = null;
		foreach ($request['recipients'] as $recipient) {
			if ($recipient['c24_signature_id'] === $signature) {
				if ($recipient['signed']) {
					// Already flagged as "signed" while processing the request.
					return new DataResponse([], Http::STATUS_OK);
				}

				$found = $recipient;
				break;
			}
		}

		if (!$found) {
			// Don't throttle, request was authenticated.
			$response = new DataResponse([], Http::STATUS_NOT_FOUND);
			return $response;
		}

		$body = file_get_contents('php://input');
		$details = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$this->manager->processSignatureDetails($request, $account, $recipient['type'], $recipient['value'], $details);
		return new DataResponse([], Http::STATUS_OK);
	}
}
