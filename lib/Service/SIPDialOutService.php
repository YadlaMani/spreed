<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
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

use OCA\Talk\Model\Attendee;
use OCA\Talk\Room;
use OCA\Talk\Signaling\BackendNotifier;
use OCA\Talk\Signaling\Responses\Response;
use OCA\Talk\Vendor\CuyZ\Valinor\Mapper\MappingError;
use OCA\Talk\Vendor\CuyZ\Valinor\Mapper\Source\Source;
use OCA\Talk\Vendor\CuyZ\Valinor\MapperBuilder;

class SIPDialOutService {

	public function __construct(
		public ParticipantService $participantService,
		public BackendNotifier $backendNotifier,
	) {
	}

	public function dialOut(Room $room): void {
		$attendees = $this->participantService->getActorsByType($room, Attendee::ACTOR_PHONES);
		foreach ($attendees as $attendee) {
			if ($attendee->getActorType() !== Attendee::ACTOR_PHONES) {
				continue;
			}

			$response = $this->backendNotifier->dialOutToAttendee($room, $attendee);
			$dialOutResponse = $this->validateDialOutResponse($response);
		}
	}

	/**
	 * @param string $response
	 * @return Response
	 * @throws \InvalidArgumentException
	 */
	protected function validateDialOutResponse(string $response): Response {
		try {
			$dialOutResponse = (new MapperBuilder())
				->mapper()
				->map(
					Response::class,
					Source::json($response)
					->map([
						'dialout' => 'dialOut',
						'dialout.callid' => 'callId',
					])
				);
		} catch (MappingError) {
			throw new \InvalidArgumentException('Not a valid dial-out response');
		}

		if ($dialOutResponse->dialOut === null) {
			throw new \InvalidArgumentException('Not a valid dial-out response');
		}

		return $dialOutResponse;
	}
}
