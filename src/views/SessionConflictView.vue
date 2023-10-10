<template>
	<NcEmptyContent class="h-100"
		:name="t('spreed', 'Duplicate session')"
		:description="t('spreed', 'You joined the conversation in another window or device. This is currently not supported by Nextcloud Talk so this session was closed.')">
		<template #icon>
			<IconInformation />
		</template>
	</NcEmptyContent>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import IconInformation from 'vue-material-design-icons/Information.vue'

export default {
	name: 'SessionConflictView',

	components: {
		NcEmptyContent,
		IconInformation,
	},

	mounted() {
		this.setPageTitle()
	},

	methods: {
		setPageTitle() {
			window.document.title = t('spreed', 'Duplicate session') + ' - ' + window.document.title
		},
	},
}
</script>

<style lang="scss" scoped>
.h-100 {
	height: 100%;
}
</style>
