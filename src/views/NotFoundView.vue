<template>
	<NcEmptyContent class="h-100"
		:name="t('spreed', 'The conversation does not exist')"
		:description="t('spreed', 'Join a conversation or start a new one!')">
		<template #icon>
			<IconMagnify />
		</template>
	</NcEmptyContent>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'NotFoundView',

	components: {
		NcEmptyContent,
		IconMagnify,
	},
}
</script>

<style lang="scss" scoped>
.h-100 {
	height: 100%;
}
</style>
