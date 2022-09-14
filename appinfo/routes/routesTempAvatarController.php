<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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

return [
	'ocs' => [
		/** @see \OCA\Talk\Controller\TempAvatarController::postAvatar() */
		['name' => 'TempAvatar#postAvatar', 'url' => '/temp-user-avatar', 'verb' => 'POST'],
		/** @see \OCA\Talk\Controller\TempAvatarController::deleteAvatar() */
		['name' => 'TempAvatar#deleteAvatar', 'url' => '/temp-user-avatar', 'verb' => 'DELETE'],
	],
];