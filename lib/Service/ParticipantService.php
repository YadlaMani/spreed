<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Service;

use OCA\Talk\Manager;
use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Config;
use OCA\Talk\Events\AddParticipantsEvent;
use OCA\Talk\Events\AttendeesAddedEvent;
use OCA\Talk\Events\AttendeesRemovedEvent;
use OCA\Talk\Events\ChatEvent;
use OCA\Talk\Events\EndCallForEveryoneEvent;
use OCA\Talk\Events\JoinRoomGuestEvent;
use OCA\Talk\Events\JoinRoomUserEvent;
use OCA\Talk\Events\ModifyEveryoneEvent;
use OCA\Talk\Events\ModifyParticipantEvent;
use OCA\Talk\Events\ParticipantEvent;
use OCA\Talk\Events\RemoveParticipantEvent;
use OCA\Talk\Events\RemoveUserEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Events\SendCallNotificationEvent;
use OCA\Talk\Events\SilentModifyParticipantEvent;
use OCA\Talk\Exceptions\ForbiddenException;
use OCA\Talk\Exceptions\InvalidPasswordException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\UnauthorizedException;
use OCA\Talk\Federation\FederationManager;
use OCA\Talk\Federation\Notifications;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Model\SelectHelper;
use OCA\Talk\Model\Session;
use OCA\Talk\Model\SessionMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Webinary;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Server;

class ParticipantService {
	protected IConfig $serverConfig;
	protected Config $talkConfig;
	protected AttendeeMapper $attendeeMapper;
	protected SessionMapper $sessionMapper;
	protected SessionService $sessionService;
	private ISecureRandom $secureRandom;
	protected IDBConnection $connection;
	private IEventDispatcher $dispatcher;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private MembershipService $membershipService;
	private Notifications $notifications;
	private ITimeFactory $timeFactory;
	private ICacheFactory $cacheFactory;

	public function __construct(IConfig $serverConfig,
								Config $talkConfig,
								AttendeeMapper $attendeeMapper,
								SessionMapper $sessionMapper,
								SessionService $sessionService,
								ISecureRandom $secureRandom,
								IDBConnection $connection,
								IEventDispatcher $dispatcher,
								IUserManager $userManager,
								IGroupManager $groupManager,
								MembershipService $membershipService,
								Notifications $notifications,
								ITimeFactory $timeFactory,
								ICacheFactory $cacheFactory) {
		$this->serverConfig = $serverConfig;
		$this->talkConfig = $talkConfig;
		$this->attendeeMapper = $attendeeMapper;
		$this->sessionMapper = $sessionMapper;
		$this->sessionService = $sessionService;
		$this->secureRandom = $secureRandom;
		$this->connection = $connection;
		$this->dispatcher = $dispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->membershipService = $membershipService;
		$this->timeFactory = $timeFactory;
		$this->cacheFactory = $cacheFactory;
		$this->notifications = $notifications;
	}

	public function updateParticipantType(Room $room, Participant $participant, int $participantType): void {
		$attendee = $participant->getAttendee();

		if ($attendee->getActorType() === Attendee::ACTOR_GROUPS) {
			// Can not promote/demote groups
			return;
		}

		$oldType = $attendee->getParticipantType();

		$event = new ModifyParticipantEvent($room, $participant, 'type', $participantType, $oldType);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_PARTICIPANT_TYPE_SET, $event);

		$attendee->setParticipantType($participantType);
		$this->attendeeMapper->update($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_PARTICIPANT_TYPE_SET, $event);
	}

	/**
	 * @throws Exception
	 * @throws ForbiddenException
	 */
	public function updatePermissions(Room $room, Participant $participant, string $method, int $newPermissions): bool {
		if ($room->getType() === Room::TYPE_ONE_TO_ONE) {
			return false;
		}

		if ($participant->hasModeratorPermissions()) {
			throw new ForbiddenException();
		}

		$attendee = $participant->getAttendee();

		if ($attendee->getActorType() === Attendee::ACTOR_GROUPS || $attendee->getActorType() === Attendee::ACTOR_CIRCLES) {
			// Can not set publishing permissions for those actor types
			return false;
		}

		$oldPermissions = $participant->getPermissions();
		if ($method === Attendee::PERMISSIONS_MODIFY_SET) {
			if ($newPermissions !== Attendee::PERMISSIONS_DEFAULT) {
				// Make sure the custom flag is set when not setting to default permissions
				$newPermissions |= Attendee::PERMISSIONS_CUSTOM;
			}
		} elseif ($method === Attendee::PERMISSIONS_MODIFY_ADD) {
			$newPermissions = $oldPermissions | $newPermissions;
		} elseif ($method === Attendee::PERMISSIONS_MODIFY_REMOVE) {
			$newPermissions = $oldPermissions & ~$newPermissions;
		} else {
			return false;
		}

		$event = new ModifyParticipantEvent($room, $participant, 'permissions', $newPermissions, $oldPermissions);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_PARTICIPANT_PERMISSIONS_SET, $event);

		$attendee->setPermissions($newPermissions);
		$this->attendeeMapper->update($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_PARTICIPANT_PERMISSIONS_SET, $event);

		return true;
	}

	public function updateAllPermissions(Room $room, string $method, int $newState): void {
		$this->attendeeMapper->modifyPermissions($room->getId(), $method, $newState);
	}

	public function updateLastReadMessage(Participant $participant, int $lastReadMessage): void {
		$attendee = $participant->getAttendee();
		$attendee->setLastReadMessage($lastReadMessage);
		$this->attendeeMapper->update($attendee);
	}

	public function updateFavoriteStatus(Participant $participant, bool $isFavorite): void {
		$attendee = $participant->getAttendee();
		$attendee->setFavorite($isFavorite);
		$this->attendeeMapper->update($attendee);
	}

	/**
	 * @param Participant $participant
	 * @param int $level
	 * @throws \InvalidArgumentException When the notification level is invalid
	 */
	public function updateNotificationLevel(Participant $participant, int $level): void {
		if (!\in_array($level, [
			Participant::NOTIFY_ALWAYS,
			Participant::NOTIFY_MENTION,
			Participant::NOTIFY_NEVER
		], true)) {
			throw new \InvalidArgumentException('Invalid notification level');
		}

		$attendee = $participant->getAttendee();
		$attendee->setNotificationLevel($level);
		$this->attendeeMapper->update($attendee);
	}

	/**
	 * @param Participant $participant
	 * @param int $level
	 */
	public function updateNotificationCalls(Participant $participant, int $level): void {
		if (!\in_array($level, [
			Participant::NOTIFY_CALLS_OFF,
			Participant::NOTIFY_CALLS_ON,
		], true)) {
			throw new \InvalidArgumentException('Invalid notification level');
		}

		$attendee = $participant->getAttendee();
		$attendee->setNotificationCalls($level);
		$this->attendeeMapper->update($attendee);
	}

	/**
	 * @param RoomService $roomService
	 * @param Room $room
	 * @param IUser $user
	 * @param string $password
	 * @param bool $passedPasswordProtection
	 * @return Participant
	 * @throws InvalidPasswordException
	 * @throws UnauthorizedException
	 */
	public function joinRoom(RoomService $roomService, Room $room, IUser $user, string $password, bool $passedPasswordProtection = false): Participant {
		$event = new JoinRoomUserEvent($room, $user, $password, $passedPasswordProtection);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_ROOM_CONNECT, $event);

		if ($event->getCancelJoin() === true) {
			$this->removeUser($room, $user, Room::PARTICIPANT_LEFT);
			throw new UnauthorizedException('Participant is not allowed to join');
		}

		try {
			$attendee = $this->attendeeMapper->findByActor($room->getId(), Attendee::ACTOR_USERS, $user->getUID());
		} catch (DoesNotExistException $e) {
			// queried here to avoid loop deps
			$manager = Server::get(Manager::class);
			$isListableByUser = $manager->isRoomListableByUser($room, $user->getUID());

			if (!$isListableByUser && !$event->getPassedPasswordProtection() && !$roomService->verifyPassword($room, $password)['result']) {
				throw new InvalidPasswordException('Provided password is invalid');
			}

			// User joining a group or public call through listing
			if (($room->getType() === Room::TYPE_GROUP || $room->getType() === Room::TYPE_PUBLIC) && $isListableByUser) {
				$this->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
					// need to use "USER" here, because "USER_SELF_JOINED" only works for public calls
					'participantType' => Participant::USER,
				]], $user);
			} elseif ($room->getType() === Room::TYPE_PUBLIC) {
				// User joining a public room, without being invited
				$this->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
					'participantType' => Participant::USER_SELF_JOINED,
				]], $user);
			} else {
				// shouldn't happen unless some code called joinRoom without previous checks
				throw new UnauthorizedException('Participant is not allowed to join');
			}

			$attendee = $this->attendeeMapper->findByActor($room->getId(), Attendee::ACTOR_USERS, $user->getUID());
		}

		$session = $this->sessionService->createSessionForAttendee($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_ROOM_CONNECT, $event);

		return new Participant($room, $attendee, $session);
	}

	/**
	 * @param RoomService $roomService
	 * @param Room $room
	 * @param string $password
	 * @param bool $passedPasswordProtection
	 * @param ?Participant $previousParticipant
	 * @return Participant
	 * @throws InvalidPasswordException
	 * @throws UnauthorizedException
	 */
	public function joinRoomAsNewGuest(RoomService $roomService, Room $room, string $password, bool $passedPasswordProtection = false, ?Participant $previousParticipant = null): Participant {
		$event = new JoinRoomGuestEvent($room, $password, $passedPasswordProtection);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_GUEST_CONNECT, $event);

		if ($event->getCancelJoin()) {
			throw new UnauthorizedException('Participant is not allowed to join');
		}

		if (!$event->getPassedPasswordProtection() && !$roomService->verifyPassword($room, $password)['result']) {
			throw new InvalidPasswordException();
		}

		$lastMessage = 0;
		if ($room->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $room->getLastMessage()->getId();
		}

		if ($previousParticipant instanceof Participant) {
			$attendee = $previousParticipant->getAttendee();
		} else {
			$randomActorId = $this->secureRandom->generate(255);

			$attendee = new Attendee();
			$attendee->setRoomId($room->getId());
			$attendee->setActorType(Attendee::ACTOR_GUESTS);
			$attendee->setActorId($randomActorId);
			$attendee->setParticipantType(Participant::GUEST);
			$attendee->setPermissions(Attendee::PERMISSIONS_DEFAULT);
			$attendee->setLastReadMessage($lastMessage);
			$this->attendeeMapper->insert($attendee);

			$attendeeEvent = new AttendeesAddedEvent($room, [$attendee]);
			$this->dispatcher->dispatchTyped($attendeeEvent);
		}

		$session = $this->sessionService->createSessionForAttendee($attendee);

		if (!$previousParticipant instanceof Participant) {
			// Update the random guest id
			$attendee->setActorId(sha1($session->getSessionId()));
			$this->attendeeMapper->update($attendee);
		}

		$this->dispatcher->dispatch(Room::EVENT_AFTER_GUEST_CONNECT, $event);

		return new Participant($room, $attendee, $session);
	}

	/**
	 * @param Room $room
	 * @param array $participants
	 * @param IUser|null $addedBy User that is attempting to add these users (must be set for federated users to be added)
	 * @throws \Exception thrown if $addedBy is not set when adding a federated user
	 */
	public function addUsers(Room $room, array $participants, ?IUser $addedBy = null): void {
		if (empty($participants)) {
			return;
		}
		$event = new AddParticipantsEvent($room, $participants, true);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_USERS_ADD, $event);

		$lastMessage = 0;
		if ($room->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $room->getLastMessage()->getId();
		}

		$attendees = [];
		foreach ($participants as $participant) {
			$readPrivacy = Participant::PRIVACY_PUBLIC;
			if ($participant['actorType'] === Attendee::ACTOR_USERS) {
				$readPrivacy = $this->talkConfig->getUserReadPrivacy($participant['actorId']);
			} elseif ($participant['actorType'] === Attendee::ACTOR_FEDERATED_USERS) {
				if ($addedBy === null) {
					throw new \Exception('$addedBy must be set to add a federated user');
				}
				$participant['accessToken'] = $this->secureRandom->generate(
					FederationManager::TOKEN_LENGTH,
					ISecureRandom::CHAR_HUMAN_READABLE
				);
			}

			$attendee = new Attendee();
			$attendee->setRoomId($room->getId());
			$attendee->setActorType($participant['actorType']);
			$attendee->setActorId($participant['actorId']);
			if (isset($participant['displayName'])) {
				$attendee->setDisplayName($participant['displayName']);
			}
			if (isset($participant['accessToken'])) {
				$attendee->setAccessToken($participant['accessToken']);
			}
			if (isset($participant['remoteId'])) {
				$attendee->setRemoteId($participant['remoteId']);
			}
			$attendee->setParticipantType($participant['participantType'] ?? Participant::USER);
			$attendee->setPermissions(Attendee::PERMISSIONS_DEFAULT);
			$attendee->setLastReadMessage($lastMessage);
			$attendee->setReadPrivacy($readPrivacy);
			try {
				$entity = $this->attendeeMapper->insert($attendee);
				$attendees[] = $attendee;

				if ($attendee->getActorType() === Attendee::ACTOR_FEDERATED_USERS) {
					$this->notifications->sendRemoteShare((string) $entity->getId(), $participant['accessToken'], $participant['actorId'], $addedBy->getDisplayName(), $addedBy->getCloudId(), 'user', $room, $this->getHighestPermissionAttendee($room));
				}
			} catch (Exception $e) {
				if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}

		if (!empty($attendees)) {
			$attendeeEvent = new AttendeesAddedEvent($room, $attendees);
			$this->dispatcher->dispatchTyped($attendeeEvent);

			$this->dispatcher->dispatch(Room::EVENT_AFTER_USERS_ADD, $event);

			$lastMessage = $event->getLastMessage();
			if ($lastMessage instanceof IComment) {
				$this->updateRoomLastMessage($room, $lastMessage);
			}
		}
	}

	protected function updateRoomLastMessage(Room $room, IComment $message): void {
		$room->setLastMessage($message);
		$lastMessageCache = $this->cacheFactory->createDistributed('talk/lastmsgid');
		$lastMessageCache->remove($room->getToken());
		$unreadCountCache = $this->cacheFactory->createDistributed('talk/unreadcount');
		$unreadCountCache->clear($room->getId() . '-');

		$event = new ChatEvent($room, $message);
		$this->dispatcher->dispatch(ChatManager::EVENT_AFTER_MULTIPLE_SYSTEM_MESSAGE_SEND, $event);
	}

	public function getHighestPermissionAttendee(Room $room): ?Attendee {
		try {
			$roomOwners = $this->attendeeMapper->getActorsByParticipantTypes($room->getId(), [Participant::OWNER]);

			if (!empty($roomOwners)) {
				foreach ($roomOwners as $owner) {
					if ($owner->getActorType() === Attendee::ACTOR_USERS) {
						return $owner;
					}
				}
			}
			$roomModerators = $this->attendeeMapper->getActorsByParticipantTypes($room->getId(), [Participant::MODERATOR]);
			if (!empty($roomOwners)) {
				foreach ($roomModerators as $moderator) {
					if ($moderator->getActorType() === Attendee::ACTOR_USERS) {
						return $moderator;
					}
				}
			}
		} catch (Exception $e) {
		}
		return null;
	}

	/**
	 * @param Room $room
	 * @param IGroup $group
	 * @param Participant[] $existingParticipants
	 */
	public function addGroup(Room $room, IGroup $group, array $existingParticipants = []): void {
		$usersInGroup = $group->getUsers();

		if (empty($existingParticipants)) {
			$existingParticipants = $this->getParticipantsForRoom($room);
		}

		$participantsByUserId = [];
		foreach ($existingParticipants as $participant) {
			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$participantsByUserId[$participant->getAttendee()->getActorId()] = $participant;
			}
		}

		$newParticipants = [];
		foreach ($usersInGroup as $user) {
			$existingParticipant = $participantsByUserId[$user->getUID()] ?? null;
			if ($existingParticipant instanceof Participant) {
				if ($existingParticipant->getAttendee()->getParticipantType() === Participant::USER_SELF_JOINED) {
					$this->updateParticipantType($room, $existingParticipant, Participant::USER);
				}

				// Participant is already in the conversation, so skip them.
				continue;
			}

			$newParticipants[] = [
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}

		try {
			$this->attendeeMapper->findByActor($room->getId(), Attendee::ACTOR_GROUPS, $group->getGID());
		} catch (DoesNotExistException $e) {
			$attendee = new Attendee();
			$attendee->setRoomId($room->getId());
			$attendee->setActorType(Attendee::ACTOR_GROUPS);
			$attendee->setActorId($group->getGID());
			$attendee->setDisplayName($group->getDisplayName());
			$attendee->setParticipantType(Participant::USER);
			$attendee->setPermissions(Attendee::PERMISSIONS_DEFAULT);
			$attendee->setReadPrivacy(Participant::PRIVACY_PRIVATE);
			$this->attendeeMapper->insert($attendee);

			$attendeeEvent = new AttendeesAddedEvent($room, [$attendee]);
			$this->dispatcher->dispatchTyped($attendeeEvent);
		}

		$this->addUsers($room, $newParticipants);
	}

	/**
	 * @param string $circleId
	 * @param string $userId
	 * @return Circle
	 * @throws ParticipantNotFoundException
	 */
	public function getCircle(string $circleId, string $userId): Circle {
		try {
			$circlesManager = Server::get(CirclesManager::class);
			$federatedUser = $circlesManager->getFederatedUser($userId, Member::TYPE_USER);
			$federatedUser->getLink($circleId);
		} catch (\Exception $e) {
			throw new ParticipantNotFoundException('Circle not found or not a member');
		}

		$circlesManager->startSession($federatedUser);
		try {
			$circle = $circlesManager->getCircle($circleId);
			$circlesManager->stopSession();
			return $circle;
		} catch (\Exception $e) {
		}

		$circlesManager->stopSession();
		throw new ParticipantNotFoundException('Circle not found or not a member');
	}

	/**
	 * @param Room $room
	 * @param Circle $circle
	 * @param Participant[] $existingParticipants
	 */
	public function addCircle(Room $room, Circle $circle, array $existingParticipants = []): void {
		$membersInCircle = $circle->getInheritedMembers();

		if (empty($existingParticipants)) {
			$existingParticipants = $this->getParticipantsForRoom($room);
		}

		$participantsByUserId = [];
		foreach ($existingParticipants as $participant) {
			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$participantsByUserId[$participant->getAttendee()->getActorId()] = $participant;
			}
		}

		$newParticipants = [];
		foreach ($membersInCircle as $member) {
			/** @var Member $member */
			if ($member->getUserType() !== Member::TYPE_USER || $member->getUserId() === '') {
				// Not a user?
				continue;
			}

			if ($member->getStatus() !== Member::STATUS_INVITED && $member->getStatus() !== Member::STATUS_MEMBER) {
				// Only allow invited and regular members
				continue;
			}

			$user = $this->userManager->get($member->getUserId());
			if (!$user instanceof IUser) {
				continue;
			}

			$existingParticipant = $participantsByUserId[$user->getUID()] ?? null;
			if ($existingParticipant instanceof Participant) {
				if ($existingParticipant->getAttendee()->getParticipantType() === Participant::USER_SELF_JOINED) {
					$this->updateParticipantType($room, $existingParticipant, Participant::USER);
				}

				// Participant is already in the conversation, so skip them.
				continue;
			}

			$newParticipants[] = [
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}

		try {
			$this->attendeeMapper->findByActor($room->getId(), Attendee::ACTOR_CIRCLES, $circle->getSingleId());
		} catch (DoesNotExistException $e) {
			$attendee = new Attendee();
			$attendee->setRoomId($room->getId());
			$attendee->setActorType(Attendee::ACTOR_CIRCLES);
			$attendee->setActorId($circle->getSingleId());
			$attendee->setDisplayName($circle->getDisplayName());
			$attendee->setParticipantType(Participant::USER);
			$attendee->setPermissions(Attendee::PERMISSIONS_DEFAULT);
			$attendee->setReadPrivacy(Participant::PRIVACY_PRIVATE);
			$this->attendeeMapper->insert($attendee);

			$attendeeEvent = new AttendeesAddedEvent($room, [$attendee]);
			$this->dispatcher->dispatchTyped($attendeeEvent);
		}

		$this->addUsers($room, $newParticipants);
	}

	/**
	 * @param Room $room
	 * @param string $email
	 * @return Participant
	 */
	public function inviteEmailAddress(Room $room, string $email): Participant {
		$lastMessage = 0;
		if ($room->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $room->getLastMessage()->getId();
		}

		$attendee = new Attendee();
		$attendee->setRoomId($room->getId());
		$attendee->setActorType(Attendee::ACTOR_EMAILS);
		$attendee->setActorId($email);

		if ($room->getSIPEnabled() !== Webinary::SIP_DISABLED
			&& $this->talkConfig->isSIPConfigured()) {
			$attendee->setPin($this->generatePin());
		}

		$attendee->setParticipantType(Participant::GUEST);
		$attendee->setLastReadMessage($lastMessage);
		$this->attendeeMapper->insert($attendee);
		// FIXME handle duplicate invites gracefully

		$attendeeEvent = new AttendeesAddedEvent($room, [$attendee]);
		$this->dispatcher->dispatchTyped($attendeeEvent);

		return new Participant($room, $attendee, null);
	}

	public function generatePinForParticipant(Room $room, Participant $participant): void {
		$attendee = $participant->getAttendee();
		if ($room->getSIPEnabled() !== Webinary::SIP_DISABLED
			&& $this->talkConfig->isSIPConfigured()
			&& ($attendee->getActorType() === Attendee::ACTOR_USERS || $attendee->getActorType() === Attendee::ACTOR_EMAILS)
			&& !$attendee->getPin()) {
			$attendee->setPin($this->generatePin());
			$this->attendeeMapper->update($attendee);
		}
	}

	public function ensureOneToOneRoomIsFilled(Room $room): void {
		if ($room->getType() !== Room::TYPE_ONE_TO_ONE) {
			return;
		}

		$users = json_decode($room->getName(), true);
		$participants = $this->getParticipantUserIds($room);
		$missingUsers = array_diff($users, $participants);

		foreach ($missingUsers as $userId) {
			$userDisplayName = $this->userManager->getDisplayName($userId);
			if ($userDisplayName !== null) {
				$this->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $userId,
					'displayName' => $userDisplayName,
					'participantType' => Participant::OWNER,
				]]);
			}
		}
	}

	public function leaveRoomAsSession(Room $room, Participant $participant): void {
		$event = new ParticipantEvent($room, $participant);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_ROOM_DISCONNECT, $event);

		$session = $participant->getSession();
		if ($session instanceof Session) {
			$dispatchLeaveCallEvents = $session->getInCall() !== Participant::FLAG_DISCONNECTED;
			if ($dispatchLeaveCallEvents) {
				$event = new ModifyParticipantEvent($room, $participant, 'inCall', Participant::FLAG_DISCONNECTED, $session->getInCall());
				$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_LEAVE_CALL, $event);
			}

			$this->sessionMapper->delete($session);

			if ($dispatchLeaveCallEvents) {
				$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_LEAVE_CALL, $event);
			}
		} else {
			$this->sessionMapper->deleteByAttendeeId($participant->getAttendee()->getId());
		}

		if ($participant->getAttendee()->getParticipantType() === Participant::USER_SELF_JOINED) {
			$this->attendeeMapper->delete($participant->getAttendee());

			$attendeeEvent = new AttendeesRemovedEvent($room, [$participant->getAttendee()]);
			$this->dispatcher->dispatchTyped($attendeeEvent);
		}

		$this->dispatcher->dispatch(Room::EVENT_AFTER_ROOM_DISCONNECT, $event);
	}

	public function removeAttendee(Room $room, Participant $participant, string $reason, bool $attendeeEventIsTriggeredAlready = false): void {
		$isUser = $participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS;

		$sessions = $this->sessionService->getAllSessionsForAttendee($participant->getAttendee());

		if ($isUser) {
			$user = $this->userManager->get($participant->getAttendee()->getActorId());
			$event = new RemoveUserEvent($room, $participant, $user, $reason, $sessions);
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_USER_REMOVE, $event);
		} else {
			$event = new RemoveParticipantEvent($room, $participant, $reason, $sessions);
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_PARTICIPANT_REMOVE, $event);
		}

		$this->sessionMapper->deleteByAttendeeId($participant->getAttendee()->getId());
		$this->attendeeMapper->delete($participant->getAttendee());

		if (!$attendeeEventIsTriggeredAlready) {
			$attendeeEvent = new AttendeesRemovedEvent($room, [$participant->getAttendee()]);
			$this->dispatcher->dispatchTyped($attendeeEvent);
		}

		if ($isUser) {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_USER_REMOVE, $event);
		} else {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_PARTICIPANT_REMOVE, $event);
		}

		if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_GROUPS) {
			$this->removeGroupMembers($room, $participant, $reason);
		} elseif ($participant->getAttendee()->getActorType() === Attendee::ACTOR_CIRCLES) {
			$this->removeCircleMembers($room, $participant, $reason);
		}
	}

	public function getActorsByType(Room $room, string $actorType): array {
		return $this->attendeeMapper->getActorsByType($room->getId(), $actorType);
	}

	public function removeGroupMembers(Room $room, Participant $removedGroupParticipant, string $reason): void {
		$removedGroup = $this->groupManager->get($removedGroupParticipant->getAttendee()->getActorId());
		if (!$removedGroup instanceof IGroup) {
			return;
		}

		$users = $this->membershipService->getUsersWithoutOtherMemberships($room, $removedGroup->getUsers());
		$attendees = [];
		foreach ($users as $user) {
			try {
				$participant = $room->getParticipant($user->getUID());
				$participantType = $participant->getAttendee()->getParticipantType();

				$attendees[] = $participant->getAttendee();
				if ($participantType === Participant::USER) {
					// Only remove normal users, not moderators/admins
					$this->removeAttendee($room, $participant, $reason, true);
				}
			} catch (ParticipantNotFoundException $e) {
			}
		}

		$attendeeEvent = new AttendeesRemovedEvent($room, $attendees);
		$this->dispatcher->dispatchTyped($attendeeEvent);
	}

	public function removeCircleMembers(Room $room, Participant $removedCircleParticipant, string $reason): void {
		try {
			$circlesManager = Server::get(CirclesManager::class);
			$circlesManager->startSuperSession();
			$circle = $circlesManager->getCircle($removedCircleParticipant->getAttendee()->getActorId());
			$circlesManager->stopSession();
		} catch (\Exception $e) {
			// Circles not enabled
			return;
		}

		$circlesManager->startSuperSession();
		try {
			$circle = $circlesManager->getCircle($removedCircleParticipant->getAttendee()->getActorId());
			$circlesManager->stopSession();
		} catch (\Exception $e) {
			$circlesManager->stopSession();
			return;
		}

		$membersInCircle = $circle->getInheritedMembers();
		$users = [];
		foreach ($membersInCircle as $member) {
			/** @var Member $member */
			if ($member->getUserType() !== Member::TYPE_USER || $member->getUserId() === '') {
				// Not a user?
				continue;
			}

			if ($member->getStatus() !== Member::STATUS_INVITED && $member->getStatus() !== Member::STATUS_MEMBER) {
				// Only allow invited and regular members
				continue;
			}

			$users[] = $this->userManager->get($member->getUserId());
		}

		$users = array_filter($users);

		if (empty($users)) {
			return;
		}

		$users = $this->membershipService->getUsersWithoutOtherMemberships($room, $users);
		$attendees = [];
		foreach ($users as $user) {
			try {
				$participant = $room->getParticipant($user->getUID());
				$participantType = $participant->getAttendee()->getParticipantType();

				$attendees[] = $participant->getAttendee();
				if ($participantType === Participant::USER) {
					// Only remove normal users, not moderators/admins
					$this->removeAttendee($room, $participant, $reason, true);
				}
			} catch (ParticipantNotFoundException $e) {
			}
		}

		$attendeeEvent = new AttendeesRemovedEvent($room, $attendees);
		$this->dispatcher->dispatchTyped($attendeeEvent);
	}

	public function removeUser(Room $room, IUser $user, string $reason): void {
		try {
			$participant = $room->getParticipant($user->getUID(), false);
		} catch (ParticipantNotFoundException $e) {
			return;
		}

		$attendee = $participant->getAttendee();
		$sessions = $this->sessionService->getAllSessionsForAttendee($attendee);

		$event = new RemoveUserEvent($room, $participant, $user, $reason, $sessions);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_USER_REMOVE, $event);

		foreach ($sessions as $session) {
			$this->sessionMapper->delete($session);
		}

		$this->attendeeMapper->delete($attendee);

		$attendeeEvent = new AttendeesRemovedEvent($room, [$attendee]);
		$this->dispatcher->dispatchTyped($attendeeEvent);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_USER_REMOVE, $event);
	}

	public function cleanGuestParticipants(Room $room): void {
		$event = new RoomEvent($room);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_GUESTS_CLEAN, $event);

		$query = $this->connection->getQueryBuilder();
		$query->selectAlias('s.id', 's_id')
			->from('talk_sessions', 's')
			->leftJoin('s', 'talk_attendees', 'a', $query->expr()->eq('s.attendee_id', 'a.id'))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_GUESTS)))
			->andWhere($query->expr()->lte('s.last_ping', $query->createNamedParameter($this->timeFactory->getTime() - Session::SESSION_TIMEOUT_KILL, IQueryBuilder::PARAM_INT)));

		$sessionTableIds = [];
		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			$sessionTableIds[] = (int) $row['s_id'];
		}
		$result->closeCursor();

		$this->sessionService->deleteSessionsById($sessionTableIds);

		$query = $this->connection->getQueryBuilder();
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq('s.attendee_id', 'a.id'))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_GUESTS)))
			->andWhere($query->expr()->isNull('s.id'));

		$attendeeIds = [];
		$attendees = [];
		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			$attendeeIds[] = (int) $row['a_id'];
			$attendees[] = $this->attendeeMapper->createAttendeeFromRow($row);
		}
		$result->closeCursor();

		$this->attendeeMapper->deleteByIds($attendeeIds);

		$attendeeEvent = new AttendeesRemovedEvent($room, $attendees);
		$this->dispatcher->dispatchTyped($attendeeEvent);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_GUESTS_CLEAN, $event);
	}

	public function endCallForEveryone(Room $room, Participant $moderator): void {
		$event = new EndCallForEveryoneEvent($room, $moderator);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_END_CALL_FOR_EVERYONE, $event);

		$participants = $this->getParticipantsInCall($room);
		$changedSessionIds = [];
		$changedUserIds = [];

		// kick out all participants out of the call
		foreach ($participants as $participant) {
			$changedSessionIds[] = $participant->getSession()->getSessionId();
			if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$changedUserIds[] = $participant->getAttendee()->getActorId();
			}
			$this->changeInCall($room, $participant, Participant::FLAG_DISCONNECTED, true);
		}

		$this->sessionMapper->resetInCallByIds($changedSessionIds);

		$event->setSessionIds($changedSessionIds);
		$event->setUserIds($changedUserIds);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_END_CALL_FOR_EVERYONE, $event);
	}

	public function changeInCall(Room $room, Participant $participant, int $flags, bool $endCallForEveryone = false, bool $silent = false): void {
		$session = $participant->getSession();
		if (!$session instanceof Session) {
			return;
		}

		$permissions = $participant->getPermissions();
		if (!($permissions & Attendee::PERMISSIONS_PUBLISH_AUDIO)) {
			$flags &= ~Participant::FLAG_WITH_AUDIO;
		}
		if (!($permissions & Attendee::PERMISSIONS_PUBLISH_VIDEO)) {
			$flags &= ~Participant::FLAG_WITH_VIDEO;
		}

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			if ($silent) {
				$event = new SilentModifyParticipantEvent($room, $participant, 'inCall', $flags, $session->getInCall(), $silent);
			} else {
				$event = new ModifyParticipantEvent($room, $participant, 'inCall', $flags, $session->getInCall(), $silent);
			}
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_JOIN_CALL, $event);
		} else {
			if ($endCallForEveryone) {
				$event = new ModifyEveryoneEvent($room, $participant, 'inCall', $flags, $session->getInCall());
			} else {
				$event = new ModifyParticipantEvent($room, $participant, 'inCall', $flags, $session->getInCall());
			}
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_LEAVE_CALL, $event);
		}

		$session->setInCall($flags);
		if (!$endCallForEveryone) {
			$this->sessionMapper->update($session);
		}

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$attendee = $participant->getAttendee();
			$attendee->setLastJoinedCall($this->timeFactory->getTime());
			$this->attendeeMapper->update($attendee);
		}

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_JOIN_CALL, $event);
		} else {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_LEAVE_CALL, $event);
		}
	}

	public function sendCallNotificationForAttendee(Room $room, Participant $currentParticipant, int $targetAttendeeId): bool {
		$attendee = $this->attendeeMapper->getById($targetAttendeeId);
		if ($attendee->getActorType() !== Attendee::ACTOR_USERS) {
			return false;
		}

		$sessions = $this->sessionMapper->findByAttendeeId($targetAttendeeId);
		foreach ($sessions as $session) {
			if ($session->getInCall() !== Participant::FLAG_DISCONNECTED) {
				return false;
			}
		}

		$this->dispatcher->dispatchTyped(new SendCallNotificationEvent(
			$room,
			$currentParticipant,
			new Participant($room, $attendee, null)
		));

		return true;
	}

	public function updateCallFlags(Room $room, Participant $participant, int $flags): void {
		$session = $participant->getSession();
		if (!$session instanceof Session) {
			return;
		}

		if (!($session->getInCall() & Participant::FLAG_IN_CALL)) {
			throw new \Exception('Participant not in call');
		}

		if (!($flags & Participant::FLAG_IN_CALL)) {
			throw new \InvalidArgumentException('Invalid flags');
		}

		$permissions = $participant->getPermissions();
		if (!($permissions & Attendee::PERMISSIONS_PUBLISH_AUDIO)) {
			$flags &= ~Participant::FLAG_WITH_AUDIO;
		}
		if (!($permissions & Attendee::PERMISSIONS_PUBLISH_VIDEO)) {
			$flags &= ~Participant::FLAG_WITH_VIDEO;
		}

		$event = new ModifyParticipantEvent($room, $participant, 'inCall', $flags, $session->getInCall());
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_UPDATE_CALL_FLAGS, $event);

		$session->setInCall($flags);
		$this->sessionMapper->update($session);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_UPDATE_CALL_FLAGS, $event);
	}

	/**
	 * @param Room $room
	 * @param string[] $userIds
	 * @param int $messageId
	 * @param string[] $usersDirectlyMentioned
	 */
	public function markUsersAsMentioned(Room $room, array $userIds, int $messageId, array $usersDirectlyMentioned): void {
		$update = $this->connection->getQueryBuilder();
		$update->update('talk_attendees')
			->set('last_mention_message', $update->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('room_id', $update->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($update->expr()->eq('actor_type', $update->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($update->expr()->in('actor_id', $update->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$update->executeStatement();

		if (!empty($usersDirectlyMentioned)) {
			$update = $this->connection->getQueryBuilder();
			$update->update('talk_attendees')
				->set('last_mention_direct', $update->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('room_id', $update->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->eq('actor_type', $update->createNamedParameter(Attendee::ACTOR_USERS)))
				->andWhere($update->expr()->in('actor_id', $update->createNamedParameter($usersDirectlyMentioned, IQueryBuilder::PARAM_STR_ARRAY)));
			$update->executeStatement();
		}
	}

	public function resetChatDetails(Room $room): void {
		$update = $this->connection->getQueryBuilder();
		$update->update('talk_attendees')
			->set('last_read_message', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_mention_message', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_mention_direct', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('room_id', $update->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)));
		$update->executeStatement();
	}

	public function updateReadPrivacyForActor(string $actorType, string $actorId, int $readPrivacy): void {
		$update = $this->connection->getQueryBuilder();
		$update->update('talk_attendees')
			->set('read_privacy', $update->createNamedParameter($readPrivacy, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('actor_type', $update->createNamedParameter($actorType)))
			->andWhere($update->expr()->eq('actor_id', $update->createNamedParameter($actorId)));
		$update->executeStatement();
	}

	public function updateDisplayNameForActor(string $actorType, string $actorId, string $displayName): void {
		$update = $this->connection->getQueryBuilder();
		$update->update('talk_attendees')
			->set('display_name', $update->createNamedParameter($displayName))
			->where($update->expr()->eq('actor_type', $update->createNamedParameter($actorType)))
			->andWhere($update->expr()->eq('actor_id', $update->createNamedParameter($actorId)));
		$update->executeStatement();
	}

	public function getLastCommonReadChatMessage(Room $room): int {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->func()->min('last_read_message'), 'last_common_read_message')
			->from('talk_attendees')
			->where($query->expr()->eq('actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('read_privacy', $query->createNamedParameter(Participant::PRIVACY_PUBLIC, IQueryBuilder::PARAM_INT)));

		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int) ($row['last_common_read_message'] ?? 0);
	}

	/**
	 * @param int[] $roomIds
	 * @return array A map of roomId => "last common read message id"
	 * @psalm-return  array<int, int>
	 */
	public function getLastCommonReadChatMessageForMultipleRooms(array $roomIds): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('room_id')
			->selectAlias($query->func()->min('last_read_message'), 'last_common_read_message')
			->from('talk_attendees')
			->where($query->expr()->eq('actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->in('room_id', $query->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($query->expr()->eq('read_privacy', $query->createNamedParameter(Participant::PRIVACY_PUBLIC, IQueryBuilder::PARAM_INT)))
			->groupBy('room_id');

		$commonReads = array_fill_keys($roomIds, 0);
		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			$commonReads[(int) $row['room_id']] = (int) $row['last_common_read_message'];
		}
		$result->closeCursor();

		return $commonReads;
	}

	/**
	 * @param Room $room
	 * @return Participant[]
	 */
	public function getParticipantsForRoom(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$query->from('talk_attendees', 'a')
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)));

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * Get all sessions and attendees without a session for the room
	 *
	 * This will return multiple items for the same attendee if the attendee
	 * has multiple sessions in the room.
	 *
	 * @param Room $room
	 * @return Participant[]
	 */
	public function getSessionsAndParticipantsForRoom(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)));

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param Room $room
	 * @param int $maxAge
	 * @return Participant[]
	 */
	public function getParticipantsForAllSessions(Room $room, int $maxAge = 0): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_sessions', 's')
			->leftJoin(
				's', 'talk_attendees', 'a',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNotNull('a.id'));

		if ($maxAge > 0) {
			$query->andWhere($query->expr()->gt('s.last_ping', $query->createNamedParameter($maxAge, IQueryBuilder::PARAM_INT)));
		}

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param Room $room
	 * @param int $maxAge
	 * @return Participant[]
	 */
	public function getParticipantsInCall(Room $room, int $maxAge = 0): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_sessions', 's')
			->leftJoin(
				's', 'talk_attendees', 'a',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('s.in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)));

		if ($maxAge > 0) {
			$query->andWhere($query->expr()->gte('s.last_ping', $query->createNamedParameter($maxAge, IQueryBuilder::PARAM_INT)));
		}

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param Room $room
	 * @param int $notificationLevel
	 * @return Participant[]
	 */
	public function getParticipantsByNotificationLevel(Room $room, int $notificationLevel): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			// Currently we only care if the user has a session at all, so we can select any: #ThisIsFine
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.notification_level', $query->createNamedParameter($notificationLevel, IQueryBuilder::PARAM_INT)));

		$participants = $this->getParticipantsFromQuery($query, $room);

		$uniqueAttendees = [];
		foreach ($participants as $participant) {
			$uniqueAttendees[$participant->getAttendee()->getId()] = $participant;
		}

		return array_values($uniqueAttendees);
	}

	/**
	 * @param IQueryBuilder $query
	 * @return Participant[]
	 */
	protected function getParticipantsFromQuery(IQueryBuilder $query, Room $room): array {
		$participants = [];
		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			$attendee = $this->attendeeMapper->createAttendeeFromRow($row);
			if (isset($row['s_id'])) {
				$session = $this->sessionMapper->createSessionFromRow($row);
			} else {
				$session = null;
			}

			$participants[] = new Participant($room, $attendee, $session);
		}
		$result->closeCursor();

		return $participants;
	}

	/**
	 * @param Room $room
	 * @param \DateTime|null $maxLastJoined
	 * @return string[]
	 */
	public function getParticipantUserIds(Room $room, \DateTime $maxLastJoined = null): array {
		$maxLastJoinedTimestamp = null;
		if ($maxLastJoined !== null) {
			$maxLastJoinedTimestamp = $maxLastJoined->getTimestamp();
		}
		$attendees = $this->attendeeMapper->getActorsByType($room->getId(), Attendee::ACTOR_USERS, $maxLastJoinedTimestamp);

		return array_map(static function (Attendee $attendee) {
			return $attendee->getActorId();
		}, $attendees);
	}

	/**
	 * @param Room $room
	 * @param \DateTime|null $maxLastJoined
	 * @return int
	 */
	public function getGuestCount(Room $room, \DateTime $maxLastJoined = null): int {
		$maxLastJoinedTimestamp = null;
		if ($maxLastJoined !== null) {
			$maxLastJoinedTimestamp = $maxLastJoined->getTimestamp();
		}

		return $this->attendeeMapper->getActorsCountByType($room->getId(), Attendee::ACTOR_GUESTS, $maxLastJoinedTimestamp);
	}

	/**
	 * @param Room $room
	 * @return string[]
	 */
	public function getParticipantUserIdsForCallNotifications(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$query->select('a.actor_id')
			->from('talk_attendees', 'a')
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->andX(
					$query->expr()->eq('s.attendee_id', 'a.id'),
					$query->expr()->neq('s.in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)),
					$query->expr()->gte('s.last_ping', $query->createNamedParameter($this->timeFactory->getTime() - Session::SESSION_TIMEOUT, IQueryBuilder::PARAM_INT)),
				)
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->eq('a.notification_calls', $query->createNamedParameter(Participant::NOTIFY_CALLS_ON)))
			->andWhere($query->expr()->isNull('s.in_call'));

		$userIds = [];
		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			$userIds[] = $row['actor_id'];
		}
		$result->closeCursor();

		return $userIds;
	}

	/**
	 * @param Room $room
	 * @return int
	 */
	public function getNumberOfUsers(Room $room): int {
		return $this->attendeeMapper->countActorsByParticipantType($room->getId(), [
			Participant::USER,
			Participant::MODERATOR,
			Participant::OWNER,
		]);
	}

	/**
	 * @param Room $room
	 * @param bool $ignoreGuestModerators
	 * @return int
	 */
	public function getNumberOfModerators(Room $room, bool $ignoreGuestModerators = true): int {
		$participantTypes = [
			Participant::MODERATOR,
			Participant::OWNER,
		];
		if (!$ignoreGuestModerators) {
			$participantTypes[] = Participant::GUEST_MODERATOR;
		}
		return $this->attendeeMapper->countActorsByParticipantType($room->getId(), $participantTypes);
	}

	/**
	 * @param Room $room
	 * @return int
	 */
	public function getNumberOfActors(Room $room): int {
		return $this->attendeeMapper->countActorsByParticipantType($room->getId(), []);
	}

	/**
	 * @param Room $room
	 * @return bool
	 */
	public function hasActiveSessions(Room $room): bool {
		$query = $this->connection->getQueryBuilder();
		$query->select('a.room_id')
			->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq(
				'a.id', 's.attendee_id'
			))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNotNull('s.id'))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (bool) $row;
	}

	/**
	 * @param Room $room
	 * @return bool
	 */
	public function hasActiveSessionsInCall(Room $room): bool {
		$query = $this->connection->getQueryBuilder();
		$query->select('a.room_id')
			->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq(
				'a.id', 's.attendee_id'
			))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNotNull('s.in_call'))
			->andWhere($query->expr()->neq('s.in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)))
			->andWhere($query->expr()->gte('s.last_ping', $query->createNamedParameter($this->timeFactory->getTime() - Session::SESSION_TIMEOUT, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (bool) $row;
	}

	protected function generatePin(int $entropy = 7): string {
		$pin = '';
		// Do not allow to start with a '0' as that is a special mode on the phone server
		// Also there are issues with some providers when you enter the same number twice
		// consecutive too fast, so we avoid this as well.
		$lastDigit = '0';
		for ($i = 0; $i < $entropy; $i++) {
			$lastDigit = $this->secureRandom->generate(1,
				str_replace($lastDigit, '', ISecureRandom::CHAR_DIGITS)
			);
			$pin .= $lastDigit;
		}

		return $pin;
	}
}
