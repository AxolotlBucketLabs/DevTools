# DevTools ecosystem inventory

This page is a point-in-time inventory of public projects using DevTools in a
GitHub Actions workflow.

## Snapshot

- Checked on 2026-11-07.
- 142 repositories were verified across five organizations.
- The audit inspected 178 non-archived repositories and 208 default-branch
  workflow files across the five organizations.
- A repository is counted when its public default-branch workflow references
  DevTools or one of DevTools' reusable workflows.
- All 142 repositories now resolve to stable `v3.0.0` through the maintained
  `@v3` channel or the exact release commit
  `04fd726101109739bf1fd629dbe8499d3e2864bb`.
- The inventory includes the 64 projects migrated from v2 or pre-v3 builder
  commits during this audit.
- The transferred ScoreHud and await-generator repositories are not counted
  because their default-branch workflows do not currently reference DevTools.
- This is a documentation snapshot, not telemetry. The list can become stale
  when projects change their workflows.
- Organization-level repositories and repositories that only mention DevTools
  in documentation are excluded.

| Organization | Verified repositories |
| --- | ---: |
| [AxolotlBucketLabs](https://github.com/AxolotlBucketLabs) | 7 |
| [NhanAZ-Plugins](https://github.com/NhanAZ-Plugins) | 94 |
| [NhanAZ-Libraries](https://github.com/NhanAZ-Libraries) | 6 |
| [thebigcrafter](https://github.com/thebigcrafter) | 23 |
| [ReinfyTeam](https://github.com/ReinfyTeam) | 12 |
| **Total** | **142** |

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

## Projects migrated during the 2026-11-07 audit

These projects were already using DevTools but still referenced the v2 channel
or a pre-v3 checkout. Their default-branch workflows now use the AxolotlBucketLabs
v3 workflow or the exact `v3.0.0` release commit.

### NhanAZ-Plugins

- [aBuyParticles](https://github.com/NhanAZ-Plugins/aBuyParticles)
- [Alias](https://github.com/NhanAZ-Plugins/Alias)
- [AntiExplosions](https://github.com/NhanAZ-Plugins/AntiExplosions)
- [AntiFallDamage](https://github.com/NhanAZ-Plugins/AntiFallDamage)
- [AntiFurry](https://github.com/NhanAZ-Plugins/AntiFurry)
- [AntiToolBox](https://github.com/NhanAZ-Plugins/AntiToolBox)
- [ArcoLife](https://github.com/NhanAZ-Plugins/ArcoLife)
- [AutoClearLagg](https://github.com/NhanAZ-Plugins/AutoClearLagg)
- [BuyFix](https://github.com/NhanAZ-Plugins/BuyFix)
- [CoinAPI](https://github.com/NhanAZ-Plugins/CoinAPI)
- [Coordinates](https://github.com/NhanAZ-Plugins/Coordinates)
- [CopyPasteBuildDP](https://github.com/NhanAZ-Plugins/CopyPasteBuildDP)
- [CustomToastExample](https://github.com/NhanAZ-Plugins/CustomToastExample)
- [DebugStick](https://github.com/NhanAZ-Plugins/DebugStick)
- [DevilMode](https://github.com/NhanAZ-Plugins/DevilMode)
- [Disable_TNT](https://github.com/NhanAZ-Plugins/Disable_TNT)
- [EcoAPIScore](https://github.com/NhanAZ-Plugins/EcoAPIScore)
- [EffectBlock-HiroTeam](https://github.com/NhanAZ-Plugins/EffectBlock-HiroTeam)
- [FaceLogin](https://github.com/NhanAZ-Plugins/FaceLogin)
- [Farms](https://github.com/NhanAZ-Plugins/Farms)
- [Fly](https://github.com/NhanAZ-Plugins/Fly)
- [FrameKeeper](https://github.com/NhanAZ-Plugins/FrameKeeper)
- [GolemAgent](https://github.com/NhanAZ-Plugins/GolemAgent)
- [GuardianJoin](https://github.com/NhanAZ-Plugins/GuardianJoin)
- [HealBar](https://github.com/NhanAZ-Plugins/HealBar)
- [IDItem](https://github.com/NhanAZ-Plugins/IDItem)
- [isEvenOrOdd](https://github.com/NhanAZ-Plugins/isEvenOrOdd)
- [ItemDropText](https://github.com/NhanAZ-Plugins/ItemDropText)
- [ItemEffect](https://github.com/NhanAZ-Plugins/ItemEffect)
- [ItemSender](https://github.com/NhanAZ-Plugins/ItemSender)
- [KeepInventory](https://github.com/NhanAZ-Plugins/KeepInventory)
- [KeepItems](https://github.com/NhanAZ-Plugins/KeepItems)
- [Mask](https://github.com/NhanAZ-Plugins/Mask)
- [NoCraftItem](https://github.com/NhanAZ-Plugins/NoCraftItem)
- [OpList](https://github.com/NhanAZ-Plugins/OpList)
- [Particles_Shape](https://github.com/NhanAZ-Plugins/Particles_Shape)
- [Pets](https://github.com/NhanAZ-Plugins/Pets)
- [PlayerVaults](https://github.com/NhanAZ-Plugins/PlayerVaults)
- [PocketMine-Plugin-Template](https://github.com/NhanAZ-Plugins/PocketMine-Plugin-Template)
- [RedSkyBlockX](https://github.com/NhanAZ-Plugins/RedSkyBlockX)
- [RegionProtect](https://github.com/NhanAZ-Plugins/RegionProtect)
- [Sell](https://github.com/NhanAZ-Plugins/Sell)
- [SimpleEconomyExample](https://github.com/NhanAZ-Plugins/SimpleEconomyExample)
- [SlapperParticle](https://github.com/NhanAZ-Plugins/SlapperParticle)
- [Spawner](https://github.com/NhanAZ-Plugins/Spawner)
- [SpawnParticles](https://github.com/NhanAZ-Plugins/SpawnParticles)
- [StealSkin](https://github.com/NhanAZ-Plugins/StealSkin)
- [TopIS](https://github.com/NhanAZ-Plugins/TopIS)
- [Uncensor](https://github.com/NhanAZ-Plugins/Uncensor)
- [Wings](https://github.com/NhanAZ-Plugins/Wings)
- [WorldsLoader](https://github.com/NhanAZ-Plugins/WorldsLoader)
- [XYZ](https://github.com/NhanAZ-Plugins/XYZ)

### NhanAZ-Libraries

- [CustomToast](https://github.com/NhanAZ-Libraries/CustomToast)
- [EcoAPI](https://github.com/NhanAZ-Libraries/EcoAPI)

### ReinfyTeam

- [libpf](https://github.com/ReinfyTeam/libpf)
- [libpmform](https://github.com/ReinfyTeam/libpmform)
- [LibVapmPMMP](https://github.com/ReinfyTeam/LibVapmPMMP)
- [Slapper](https://github.com/ReinfyTeam/Slapper)

### thebigcrafter

- [Commando](https://github.com/thebigcrafter/Commando)
- [Hydrogen](https://github.com/thebigcrafter/Hydrogen)
- [ktpmpl-cfs](https://github.com/thebigcrafter/ktpmpl-cfs)
- [pmforms](https://github.com/thebigcrafter/pmforms)
- [promise](https://github.com/thebigcrafter/promise)
- [TrashCan](https://github.com/thebigcrafter/TrashCan)

## Platform integrations

- BedrockNexus is intentionally excluded from this migration inventory. Its
  workflow template will be updated separately.
- [BedrockNexus](https://bedrocknexus.com/) provides an official
  [Axolotl-PM workflow template](https://github.com/BedrockNexus/plugin-workflows/blob/main/templates/axolotl.yml)
  powered by DevTools.
