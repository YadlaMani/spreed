<!--
  - @copyright Copyright (c) 2023 Maksim Sukharev <antreesy.web@gmail.com>
  -
  - @author Maksim Sukharev <antreesy.web@gmail.com>
  -
  - @license AGPL-3.0-or-later
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
-->

<template>
	<NcPopover id="dial-popover"
		popover-base-class="dial-popover"
		:container="container"
		@show="prefillCode">
		<template #trigger>
			<NcButton>
				<template #icon>
					<Dialpad :size="20" />
				</template>
			</NcButton>
		</template>

		<div ref="panel"
			class="dial-panel"
			tabindex="0"
			@keydown.capture="handleKeyDown">
			<NcSelect class="dial-panel__select"
				:value="region"
				:options="options"
				:append-to-body="false"
				:clearable="false"
				:placeholder="t('spreed', 'Select a region')"
				label="dial_and_name"
				@option:selected="dialCode">
				<template #option="{ emoji, dial_code, name }">
					<div class="dial-panel__select-option">
						<p>{{ emoji }} {{ dial_code }}</p>
						<em>{{ name }}</em>
					</div>
				</template>
				<template #selected-option="{ emoji, dial_code, name }">
					<span class="dial-panel__select-option">
						<b>{{ emoji }} {{ dial_code }}</b> {{ name }}
					</span>
				</template>
			</NcSelect>

			<NcButton v-for="button in buttons"
				:key="button.value"
				class="dial-panel__button"
				@click="dialType(button.value)">
				{{ button.label }}
			</NcButton>
		</div>
	</NcPopover>
</template>

<script>
import { getLocale } from '@nextcloud/l10n'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcPopover from '@nextcloud/vue/dist/Components/NcPopover.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

import Dialpad from 'vue-material-design-icons/Dialpad.vue'

import { regionCodes } from '../regionCodes.js'

const allowedKeys = /^[0-9+\-()*#\s]+$/

const buttons = [
	{ value: '1', label: '1', },
	{ value: '2', label: '2', },
	{ value: '3', label: '3', },
	{ value: '4', label: '4', },
	{ value: '5', label: '5', },
	{ value: '6', label: '6', },
	{ value: '7', label: '7', },
	{ value: '8', label: '8', },
	{ value: '9', label: '9', },
	{ value: '*', label: '*', },
	{ value: '0', label: '0', },
	{ value: '#', label: '#', },
]

export default {
	name: 'DialpadPanel',

	components: {
		Dialpad,
		NcButton,
		NcPopover,
		NcSelect,
	},

	props: {
		/**
		 * The conversation token of the message to be replied to.
		 */
		token: {
			type: String,
			default: '',
		},

		/**
		 * The CSS selector to attach popover to.
		 */
		container: {
			type: String,
			default: '#dial-popover',
		},
	},

	emits: ['dial:type'],

	setup() {
		const options = Object.values(regionCodes).map(region =>
			({ ...region, dial_and_name: region.dial_code + ' ' + region.name })
		)

		return {
			buttons,
			options,
		}
	},

	data() {
		return {
			region: null,
		}
	},

	mounted() {
	},

	methods: {
		dialType(value) {
			this.$emit('dial:type', { type: 'add', value })
		},

		dialCode(value) {
			this.region = value
			this.$emit('dial:type', { type: 'set', value: value.dial_code })

			this.$nextTick(() => {
				this.$refs.panel?.focus()
			})
		},

		prefillCode() {
			const locale = getLocale()
			if (regionCodes[locale]) {
				this.region = regionCodes[locale]
			} else if (regionCodes[locale.split('_').pop()]) {
				this.region = regionCodes[locale.split('_').pop()]
			}

			if (this.region) {
				this.dialCode(this.region)
			}
		},

		handleKeyDown(event) {
			if (allowedKeys.test(event.key)) {
				this.dialType(event.key)
			} else if (event.key === 'Backspace') {
				this.$emit('dial:type', { type: 'subtract', value: event })
			} else if (event.key === 'Enter' && !event.target.classList.contains('vs__search')) {
				this.$emit('dial:type', { type: 'confirm', value: event })
			}
		},
	},
}
</script>

<style lang="scss">
.dial-popover .v-popper__inner {
	overflow: visible !important;
}
</style>

<style lang="scss" scoped>
.dial-panel {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	grid-template-rows: repeat(4, 1fr);
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-large);

	.dial-panel__button {
		width: calc(var(--default-clickable-area) * 1.5);
		border-radius: var(--border-radius-large);
	}

	.dial-panel__select {
		grid-column: span 3;
		min-width: calc(var(--default-clickable-area) * 4.5 + var(--default-grid-baseline) * 4);
		max-width: calc(var(--default-clickable-area) * 4.5 + var(--default-grid-baseline) * 4);

		&-option {
			overflow: hidden;
			white-space: nowrap;
			text-overflow: ellipsis;
		}

    // To not ellipsize results too early
    :deep(.vs__selected, .vs__selected + .vs__search) {
      padding: 0;
    }
	}
}
</style>
