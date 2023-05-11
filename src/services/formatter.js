/**
 * @copyright Copyright (c) 2023, struktur AG.
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
 */

import moment from '@nextcloud/moment'

/**
 * Format the given date. Can be a string, Date or moment instance.
 *
 * @param {object} d the date to format
 */
function formatDate(d) {
	if (!d) {
		return d
	}

	const m = moment(d)
	return m.format('LL') + ' ' + m.format('LTS')
}

export {
	formatDate,
}
