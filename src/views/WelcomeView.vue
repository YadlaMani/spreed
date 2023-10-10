<template>
	<NcEmptyContent class="h-100"
		:name="t('spreed', 'Join a conversation or start a new one')"
		:description="t('spreed', 'Say hi to your friends and colleagues!')">
		<template #icon>
			<NcIconSvgWrapper :svg="IconTalk" />
		</template>
	</NcEmptyContent>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcIconSvgWrapper from '@nextcloud/vue/dist/Components/NcIconSvgWrapper.js'
import IconTalk from '../../img/app-dark.svg?raw'

export default {
	name: 'WelcomeView',

	components: {
		NcEmptyContent,
		NcIconSvgWrapper
	},
	setup() {
		return {
			IconTalk,
		}
	}
}
</script>

<style lang="scss" scoped>
.h-100 {
	height: 100%;
}
</style>
