# DevTools ecosystem inventory

This page is a point-in-time inventory of public projects using DevTools in a
GitHub Actions workflow.

## Snapshot

- Checked on 2026-11-06.
- 78 repositories were verified across five organizations.
- A repository is counted when its public default-branch workflow references
  DevTools or one of DevTools' reusable workflows.
- At the time of the snapshot, all 78 repositories resolved to stable
  `v3.0.0`. Of the workflow references, 75 used the maintained `@v3` channel,
  while 10 pinned the `04fd726101109739bf1fd629dbe8499d3e2864bb` release commit
  directly.
- The transferred ScoreHud and await-generator repositories are not counted
  because their default-branch workflows do not currently reference DevTools.
- This is a documentation snapshot, not telemetry. The list can become stale
  when projects change their workflows.
- Organization-level repositories and repositories that only mention DevTools
  in documentation are excluded.

| Organization | Verified repositories |
| --- | ---: |
| [AxolotlBucketLabs](https://github.com/AxolotlBucketLabs) | 7 |
| [NhanAZ-Plugins](https://github.com/NhanAZ-Plugins) | 42 |
| [NhanAZ-Libraries](https://github.com/NhanAZ-Libraries) | 4 |
| [thebigcrafter](https://github.com/thebigcrafter) | 17 |
| [ReinfyTeam](https://github.com/ReinfyTeam) | 8 |
| **Total** | **78** |

## AxolotlBucketLabs

- [arithmexp](https://github.com/AxolotlBucketLabs/arithmexp)
- [FormAPI](https://github.com/AxolotlBucketLabs/FormAPI)
- [PersonaSkin](https://github.com/AxolotlBucketLabs/PersonaSkin)
- [pmforms](https://github.com/AxolotlBucketLabs/pmforms)
- [PureChat](https://github.com/AxolotlBucketLabs/PureChat)
- [PurePerms](https://github.com/AxolotlBucketLabs/PurePerms)
- [SimplePacketHandler](https://github.com/AxolotlBucketLabs/SimplePacketHandler)

## NhanAZ-Plugins

- [AdvancedRules](https://github.com/NhanAZ-Plugins/AdvancedRules)
- [animated-Wings](https://github.com/NhanAZ-Plugins/animated-Wings)
- [AnonChat](https://github.com/NhanAZ-Plugins/AnonChat)
- [AntiServerStop](https://github.com/NhanAZ-Plugins/AntiServerStop)
- [AntiTrampleFarmland](https://github.com/NhanAZ-Plugins/AntiTrampleFarmland)
- [BackgroundMusic](https://github.com/NhanAZ-Plugins/BackgroundMusic)
- [BanItem](https://github.com/NhanAZ-Plugins/BanItem)
- [BetterCancel](https://github.com/NhanAZ-Plugins/BetterCancel)
- [BlockDataExample](https://github.com/NhanAZ-Plugins/BlockDataExample)
- [BlockDataRecorder](https://github.com/NhanAZ-Plugins/BlockDataRecorder)
- [BlockGenerationMachine](https://github.com/NhanAZ-Plugins/BlockGenerationMachine)
- [BlockGenerator](https://github.com/NhanAZ-Plugins/BlockGenerator)
- [Calculator](https://github.com/NhanAZ-Plugins/Calculator)
- [CommandBlocker](https://github.com/NhanAZ-Plugins/CommandBlocker)
- [CropGrowth](https://github.com/NhanAZ-Plugins/CropGrowth)
- [CropsXP](https://github.com/NhanAZ-Plugins/CropsXP)
- [CustomJoinSound](https://github.com/NhanAZ-Plugins/CustomJoinSound)
- [DataCleaner](https://github.com/NhanAZ-Plugins/DataCleaner)
- [DumpIdItem](https://github.com/NhanAZ-Plugins/DumpIdItem)
- [EmojiAttack](https://github.com/NhanAZ-Plugins/EmojiAttack)
- [FormDebug](https://github.com/NhanAZ-Plugins/FormDebug)
- [FrozenEffect](https://github.com/NhanAZ-Plugins/FrozenEffect)
- [HealthLimit](https://github.com/NhanAZ-Plugins/HealthLimit)
- [KeepExp](https://github.com/NhanAZ-Plugins/KeepExp)
- [NapThe](https://github.com/NhanAZ-Plugins/NapThe)
- [NoAD](https://github.com/NhanAZ-Plugins/NoAD)
- [NoEmoteMessage](https://github.com/NhanAZ-Plugins/NoEmoteMessage)
- [NoFlyWorld](https://github.com/NhanAZ-Plugins/NoFlyWorld)
- [NoTP](https://github.com/NhanAZ-Plugins/NoTP)
- [OhMyTest](https://github.com/NhanAZ-Plugins/OhMyTest)
- [OnlyOneBiome](https://github.com/NhanAZ-Plugins/OnlyOneBiome)
- [ProfanityFilter](https://github.com/NhanAZ-Plugins/ProfanityFilter)
- [RainbowChat](https://github.com/NhanAZ-Plugins/RainbowChat)
- [RevivalTax](https://github.com/NhanAZ-Plugins/RevivalTax)
- [RulesBook](https://github.com/NhanAZ-Plugins/RulesBook)
- [SimpleEconomy](https://github.com/NhanAZ-Plugins/SimpleEconomy)
- [SoundAPI](https://github.com/NhanAZ-Plugins/SoundAPI)
- [Stalker](https://github.com/NhanAZ-Plugins/Stalker)
- [TerrainGenerator](https://github.com/NhanAZ-Plugins/TerrainGenerator)
- [TradeNPC](https://github.com/NhanAZ-Plugins/TradeNPC)
- [UnlimitedSlots](https://github.com/NhanAZ-Plugins/UnlimitedSlots)
- [VisibleBorder](https://github.com/NhanAZ-Plugins/VisibleBorder)

## NhanAZ-Libraries

- [BlockData](https://github.com/NhanAZ-Libraries/BlockData)
- [libBedrock](https://github.com/NhanAZ-Libraries/libBedrock)
- [libRegRsp](https://github.com/NhanAZ-Libraries/libRegRsp)
- [QueryServer](https://github.com/NhanAZ-Libraries/QueryServer)

## thebigcrafter

- [AdvancedPluginManager](https://github.com/thebigcrafter/AdvancedPluginManager)
- [BetterConfig](https://github.com/thebigcrafter/BetterConfig)
- [HyperBackup](https://github.com/thebigcrafter/HyperBackup)
- [KygekClearConsole](https://github.com/thebigcrafter/KygekClearConsole)
- [KygekEasyGamemode](https://github.com/thebigcrafter/KygekEasyGamemode)
- [KygekEatHeal](https://github.com/thebigcrafter/KygekEatHeal)
- [KygekJoinUI](https://github.com/thebigcrafter/KygekJoinUI)
- [KygekLastPosition](https://github.com/thebigcrafter/KygekLastPosition)
- [KygekPingTPS](https://github.com/thebigcrafter/KygekPingTPS)
- [KygekPotionDisabler](https://github.com/thebigcrafter/KygekPotionDisabler)
- [KygekRanksUI](https://github.com/thebigcrafter/KygekRanksUI)
- [KygekRulesUI](https://github.com/thebigcrafter/KygekRulesUI)
- [KygekTagsShop](https://github.com/thebigcrafter/KygekTagsShop)
- [KygekWhitelistKick](https://github.com/thebigcrafter/KygekWhitelistKick)
- [LuckyPlayer](https://github.com/thebigcrafter/LuckyPlayer)
- [XPShop](https://github.com/thebigcrafter/XPShop)
- [oh-my-pmmp](https://github.com/thebigcrafter/oh-my-pmmp)

## ReinfyTeam

- [AntiVPN](https://github.com/ReinfyTeam/AntiVPN)
- [DiscordWebhookAPI](https://github.com/ReinfyTeam/DiscordWebhookAPI)
- [FakeWaterdogTransfer](https://github.com/ReinfyTeam/FakeWaterdogTransfer)
- [FancyPlayerCount](https://github.com/ReinfyTeam/FancyPlayerCount)
- [SlapBack](https://github.com/ReinfyTeam/SlapBack)
- [SlapperCooldown](https://github.com/ReinfyTeam/SlapperCooldown)
- [SlapperParticles](https://github.com/ReinfyTeam/SlapperParticles)
- [SlapperRotation](https://github.com/ReinfyTeam/SlapperRotation)

## Platform integrations

- BedrockNexus is intentionally excluded from this migration inventory. Its
  workflow template will be updated separately.
- [BedrockNexus](https://bedrocknexus.com/) provides an official
  [Axolotl-PM workflow template](https://github.com/BedrockNexus/plugin-workflows/blob/main/templates/axolotl.yml)
  powered by DevTools.
