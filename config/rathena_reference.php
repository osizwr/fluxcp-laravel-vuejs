<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| rAthena reference data
|--------------------------------------------------------------------------
|
| Job and homunculus class names, ported from FluxCP's config/jobs.php and
| config/homunculus.php.
|
| These are names for ids the emulator stores as bare integers, and rAthena
| does not expose them to the panel, so they have to live somewhere. They are
| configuration rather than code because a server with custom jobs needs to
| extend them, which is exactly why FluxCP kept them in config too.
|
| Deliberately not duplicated in the frontend: the API returns the resolved
| name alongside the id, so this mapping exists once.
|
*/

return [

    /*
     * Values of char.class.
     */
    'jobs' => [
        0 => 'Novice',
        1 => 'Swordsman',
        2 => 'Mage',
        3 => 'Archer',
        4 => 'Acolyte',
        5 => 'Merchant',
        6 => 'Thief',
        7 => 'Knight',
        8 => 'Priest',
        9 => 'Wizard',
        10 => 'Blacksmith',
        11 => 'Hunter',
        12 => 'Assassin',
        14 => 'Crusader',
        15 => 'Monk',
        16 => 'Sage',
        17 => 'Rogue',
        18 => 'Alchemist',
        19 => 'Bard',
        20 => 'Dancer',
        22 => 'Wedding',
        23 => 'Super Novice',
        24 => 'Gunslinger',
        25 => 'Ninja',
        26 => 'Xmas',
        27 => 'Summer',
        28 => 'Hanbok',
        29 => 'Oktoberfest',
        4001 => 'High Novice',
        4002 => 'High Swordsman',
        4003 => 'High Mage',
        4004 => 'High Archer',
        4005 => 'High Acolyte',
        4006 => 'High Merchant',
        4007 => 'High Thief',
        4008 => 'Lord Knight',
        4009 => 'High Priest',
        4010 => 'High Wizard',
        4011 => 'Whitesmith',
        4012 => 'Sniper',
        4013 => 'Assassin Cross',
        4015 => 'Paladin',
        4016 => 'Champion',
        4017 => 'Professor',
        4018 => 'Stalker',
        4019 => 'Creator',
        4020 => 'Clown',
        4021 => 'Gypsy',
        4023 => 'Baby',
        4024 => 'Baby Swordsman',
        4025 => 'Baby Mage',
        4026 => 'Baby Archer',
        4027 => 'Baby Acolyte',
        4028 => 'Baby Merchant',
        4029 => 'Baby Thief',
        4030 => 'Baby Knight',
        4031 => 'Baby Priest',
        4032 => 'Baby Wizard',
        4033 => 'Baby Blacksmith',
        4034 => 'Baby Hunter',
        4035 => 'Baby Assassin',
        4037 => 'Baby Crusader',
        4038 => 'Baby Monk',
        4039 => 'Baby Sage',
        4040 => 'Baby Rogue',
        4041 => 'Baby Alchemist',
        4042 => 'Baby Bard',
        4043 => 'Baby Dancer',
        4045 => 'Super Baby',
        4046 => 'Taekwon',
        4047 => 'Star Gladiator',
        4049 => 'Soul Linker',
        4050 => 'Jiang Shi',
        4051 => 'Death Knight',
        4052 => 'Dark Collector',
        4054 => 'Rune Knight',
        4055 => 'Warlock',
        4056 => 'Ranger',
        4057 => 'Arch Bishop',
        4058 => 'Mechanic',
        4059 => 'Guillotine Cross',
        4060 => 'Rune Knight+',
        4061 => 'Warlock+',
        4062 => 'Ranger+',
        4063 => 'Arch Bishop+',
        4064 => 'Mechanic+',
        4065 => 'Guillotine Cross+',
        4066 => 'Royal Guard',
        4067 => 'Sorcerer',
        4068 => 'Minstrel',
        4069 => 'Wanderer',
        4070 => 'Sura',
        4071 => 'Genetic',
        4072 => 'Shadow Chaser',
        4073 => 'Royal Guard+',
        4074 => 'Sorcerer+',
        4075 => 'Minstrel+',
        4076 => 'Wanderer+',
        4077 => 'Sura+',
        4078 => 'Genetic+',
        4079 => 'Shadow Chaser+',
        4096 => 'Baby Rune Knight',
        4097 => 'Baby Warlock',
        4098 => 'Baby Ranger',
        4099 => 'Baby Arch Bishop',
        4100 => 'Baby Mechanic',
        4101 => 'Baby Guillotine Cross',
        4102 => 'Baby Royal Guard',
        4103 => 'Baby Sorcerer',
        4104 => 'Baby Minstrel',
        4105 => 'Baby Wanderer',
        4106 => 'Baby Sura',
        4107 => 'Baby Genetic',
        4108 => 'Baby Shadow Chaser',
        4190 => 'Expanded Super Novice',
        4191 => 'Expanded Super Baby',
        4211 => 'Kagerou',
        4212 => 'Oboro',
        4215 => 'Rebellion',
        4218 => 'Summoner',
        4220 => 'Baby Summoner',
        4222 => 'Baby Ninja',
        4223 => 'Baby Kagero',
        4224 => 'Baby Oboro',
        4225 => 'Baby Taekwon',
        4226 => 'Baby Star Gladiator',
        4227 => 'Baby Soul Linker',
        4228 => 'Baby Gunslinger',
        4229 => 'Baby Rebellion',
        4239 => 'Star Emperor',
        4240 => 'Soul Reaper',
        4241 => 'Baby Star Emperor',
        4242 => 'Baby Soul Reaper',
        4252 => 'Dragon Knight',
        4253 => 'Meister',
        4254 => 'Shadow Cross',
        4255 => 'Arch Mage',
        4256 => 'Cardinal',
        4257 => 'WindHawk',
        4258 => 'Imperial Guard',
        4259 => 'Biolo',
        4260 => 'Abyss Chaser',
        4261 => 'Elemental Master',
        4262 => 'Inquisitor',
        4263 => 'Troubadour',
        4264 => 'Trouvere',
        4302 => 'Sky Emperor',
        4303 => 'Soul Ascetic',
        4304 => 'Shinkiro',
        4305 => 'Shiranui',
        4306 => 'Night Watch',
        4307 => 'Hyper Novice',
        4308 => 'Spirit Handler',
    ],

    /*
     * Values of homunculus.class.
     */
    'homunculus' => [
        6001 => 'Lif',
        6009 => 'Lif',
        6005 => 'Lif',
        6013 => 'Lif',
        6002 => 'Amistr',
        6010 => 'Amistr',
        6006 => 'Amistr',
        6014 => 'Amistr',
        6003 => 'Filir',
        6011 => 'Filir',
        6007 => 'Filir',
        6015 => 'Filir',
        6004 => 'Vanilmirth',
        6012 => 'Vanilmirth',
        6008 => 'Vanilmirth',
        6016 => 'Vanilmirth',
        6048 => 'Eira',
        6049 => 'Bayeri',
        6050 => 'Sera',
        6051 => 'Dieter',
        6052 => 'Elanor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Item and monster attribute columns
    |--------------------------------------------------------------------------
    |
    | Modern rAthena stores these as one boolean column per attribute rather
    | than as a bitmask: `item_db_re` has a `location_head_top` column, a
    | `job_swordman` column, a `trade_nodrop` column and so on. The maps below
    | turn those column names into labels.
    |
    | Ported from FluxCP's config/itemtypes.php, equip_locations.php,
    | equip_jobs.php, equip_upper.php, trade_restrictions.php, itemsflags.php
    | and monstermode.php.
    |
    | The job and class lists are split because the renewal entries only exist
    | on a renewal server; reading them on a pre-renewal one asks for columns
    | that are not there.
    |
    */

    'item_types' => [
        'ammo' => 'Ammo',
        'armor' => 'Armor',
        'card' => 'Card',
        'cash' => 'Cash Shop Reward',
        'delayconsume' => 'Delay Consume',
        'etc' => 'Etc',
        'healing' => 'Healing',
        'petarmor' => 'Pet Armor',
        'petegg' => 'Pet Egg',
        'shadowgear' => 'Shadow Equipment',
        'usable' => 'Usable',
        'weapon' => 'Weapon',
    ],

    'equip_locations' => [
        'location_head_top' => 'Upper Headgear',
        'location_head_mid' => 'Middle Headgear',
        'location_head_low' => 'Lower Headgear',
        'location_armor' => 'Armor',
        'location_right_hand' => 'Main Hand',
        'location_left_hand' => 'Off Hand',
        'location_garment' => 'Garment',
        'location_shoes' => 'Footgear',
        'location_right_accessory' => 'Accessory Right',
        'location_left_accessory' => 'Accessory Left',
        'location_costume_head_top' => 'Costume Top Headgear',
        'location_costume_head_mid' => 'Costume Mid Headgear',
        'location_costume_head_low' => 'Costume Low Headgear',
        'location_costume_garment' => 'Costume Garment',
        'location_ammo' => 'Ammo',
        'location_shadow_armor' => 'Shadow Armor',
        'location_shadow_weapon' => 'Shadow Weapon',
        'location_shadow_shield' => 'Shadow Shield',
        'location_shadow_shoes' => 'Shadow Shoes',
        'location_shadow_right_accessory' => 'Shadow Accessory Right (Earring)',
        'location_shadow_left_accessory' => 'Shadow Accessory Left (Pendant)',
    ],

    'equip_jobs' => [
        'base' => [
            'job_all' => 'All jobs',
            'job_novice' => 'Novice',
            'job_supernovice' => 'Super Novice',
            'job_swordman' => 'Swordman',
            'job_mage' => 'Mage',
            'job_archer' => 'Archer',
            'job_acolyte' => 'Acolyte',
            'job_merchant' => 'Merchant',
            'job_thief' => 'Thief',
            'job_knight' => 'Knight',
            'job_priest' => 'Priest',
            'job_wizard' => 'Wizard',
            'job_blacksmith' => 'Blacksmith',
            'job_hunter' => 'Hunter',
            'job_assassin' => 'Assassin',
            'job_crusader' => 'Crusader',
            'job_monk' => 'Monk',
            'job_sage' => 'Sage',
            'job_rogue' => 'Rogue',
            'job_alchemist' => 'Alchemist',
            'job_barddancer' => 'Bard / Dancer',
            'job_taekwon' => 'Taekwon',
            'job_stargladiator' => 'Star Gladiator',
            'job_soullinker' => 'Soul Linker',
            'job_gunslinger' => 'Gunslinger',
            'job_ninja' => 'Ninja',
        ],
        'renewal' => [
            'job_kagerouoboro' => 'Kagerou / Oboro',
            'job_rebellion' => 'Rebellion',
            'job_summoner' => 'Summoner',
        ],
    ],

    'equip_classes' => [
        'base' => [
            'class_all' => 'All classes',
            'class_normal' => 'Normal',
            'class_upper' => 'Upper',
            'class_baby' => 'Baby',
        ],
        'renewal' => [
            'class_third' => 'Third',
            'class_third_upper' => 'Third Upper',
            'class_third_baby' => 'Third Baby',
        ],
    ],

    'trade_restrictions' => [
        'trade_nodrop' => "Can't be dropped",
        'trade_notrade' => "Can't be traded with another player",
        'trade_tradepartner' => "Can't be traded with a partner",
        'trade_nosell' => "Can't be sold to an NPC",
        'trade_nocart' => "Can't be put in a cart",
        'trade_nostorage' => "Can't be put in storage",
        'trade_noguildstorage' => "Can't be put in guild storage",
        'trade_nomail' => "Can't be attached to mail",
        'trade_noauction' => "Can't be auctioned",
    ],

    'item_flags' => [
        'flag_buyingstore' => 'Available to buying stores',
        'flag_deadbranch' => 'Dead branch type',
        'flag_container' => 'Is a container',
        'flag_uniqueid' => 'Unique stack',
        'flag_bindonequip' => 'Bound to the character on equipping',
        'flag_dropannounce' => 'Announced to the character on drop',
        'flag_noconsume' => 'Not consumed on use',
    ],

    'monster_modes' => [
        'mode_aggressive' => 'Aggressive',
        'mode_angry' => 'Angry',
        'mode_assist' => 'Assist',
        'mode_canattack' => 'Can attack',
        'mode_canmove' => 'Can move',
        'mode_castsensorchase' => 'Cast sensor chase',
        'mode_castsensoridle' => 'Cast sensor idle',
        'mode_changechase' => 'Change chase',
        'mode_changetargetchase' => 'Change target chase',
        'mode_changetargetmelee' => 'Change target melee',
        'mode_detector' => 'Detector',
        'mode_fixeditemdrop' => 'Fixed item drop',
        'mode_ignoremagic' => 'Ignores magic',
        'mode_ignoremelee' => 'Ignores melee',
        'mode_ignoremisc' => 'Ignores misc',
        'mode_ignoreranged' => 'Ignores ranged',
        'mode_knockbackimmune' => 'Knockback immune',
        'mode_looter' => 'Looter',
        'mode_mvp' => 'MVP',
        'mode_norandomwalk' => 'Plant',
        'mode_randomtarget' => 'Random target',
        'mode_skillimmune' => 'Skill immune',
        'mode_statusimmune' => 'Status immune',
        'mode_targetweak' => 'Targets the weak',
        'mode_teleportblock' => 'Teleport block',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fame ladder job classes
    |--------------------------------------------------------------------------
    |
    | rAthena awards fame points to three job branches, and each has its own
    | ladder. The class ids are the ones the emulator writes to `char.class`,
    | including the baby and third-class variants -- a ladder that listed only
    | the base class would be empty on a server where everybody has rebirthed.
    |
    | Ported from FluxCP's jobs_alchemist.php and jobs_blacksmith.php.
    |
    */

    'fame_classes' => [
        'alchemist' => [
            18 => 'Alchemist',
            4019 => 'Creator',
            4041 => 'Baby Alchemist',
            4071 => 'Genetic',
            4078 => 'Genetic+',
            4107 => 'Baby Genetic',
            4259 => 'Biolo',
        ],

        'blacksmith' => [
            10 => 'Blacksmith',
            4011 => 'Whitesmith',
            4033 => 'Baby Blacksmith',
            4058 => 'Mechanic',
            4064 => 'Mechanic+',
            4100 => 'Baby Mechanic',
            4253 => 'Meister',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Castles
    |--------------------------------------------------------------------------
    |
    | War of Emperium castles, by the id rAthena uses in `guild_castle`. The
    | names are the iRO ones, which is what FluxCP shipped.
    |
    | Removing an entry removes that castle from the castles page and from the
    | guild ladder's castle count, which is how an operator excludes the
    | novice castles or a set their server does not run.
    |
    | FluxCP's castlenames.php also carried the 44 kRO names inside a block
    | comment, as an alternative set to paste over these. They are not
    | reproduced here -- an operator who wants them edits this array, which is
    | the same amount of work as un-commenting was, and carrying a second dead
    | copy in a comment is how the two drift apart.
    |
    */

    'castles' => [
        0 => 'Neuschwanstein',
        1 => 'Hohenschwangau',
        2 => 'Nuenberg',
        3 => 'Wuerzburg',
        4 => 'Rothenburg',
        5 => 'Repherion',
        6 => 'Eeyolbriggar',
        7 => 'Yesnelph',
        8 => 'Bergel',
        9 => 'Mersetzdeitz',
        10 => 'Bright Arbor',
        11 => 'Scarlet Palace',
        12 => 'Holy Shadow',
        13 => 'Sacred Altar',
        14 => 'Bamboo Grove Hill',
        15 => 'Kriemhild',
        16 => 'Swanhild',
        17 => 'Fadhgridh',
        18 => 'Skoegul',
        19 => 'Gondul',
        20 => 'Novice Aldebaran',
        21 => 'Novice Geffen',
        22 => 'Novice Payon',
        23 => 'Novice Prontera',
        24 => 'Himinn',
        25 => 'Andlangr',
        26 => 'Viblainn',
        27 => 'Hljod',
        28 => 'Skidbladnir',
        29 => 'Mardol',
        30 => 'Cyr',
        31 => 'Horn',
        32 => 'Gefn',
        33 => 'Bandis',
        34 => 'Leilah',
        35 => 'Pavianne',
        36 => 'Jasmine',
        37 => 'Roxie',
        38 => 'Curly Sue',
        39 => 'Gaebolg',
        40 => 'Richard',
        41 => 'Wigner',
        42 => 'Heine',
        43 => 'Nerious',
    ],

    /*
    |--------------------------------------------------------------------------
    | Item shop categories
    |--------------------------------------------------------------------------
    |
    | Ported from FluxCP's shopcategories.php. A shop item's `category` column
    | holds one of these ids.
    |
    */

    'shop_categories' => [
        0 => 'Headgears',
        1 => 'Wings',
        2 => 'Armors',
        3 => 'Weapons',
        4 => 'Healing Items',
        5 => 'Pets',
        6 => 'Miscellaneous',
        7 => 'Cards',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gender-linked job classes
    |--------------------------------------------------------------------------
    |
    | Jobs that exist only for one gender: Bard and Dancer and everything they
    | become. An account holding one of these cannot change its gender, because
    | rAthena would leave the character as a class its new gender cannot be --
    | which the client renders as an invisible or crashing character.
    |
    | Ported from FluxCP's jobs_gender_linked.php.
    |
    */

    'gender_linked_classes' => [
        19 => 'Bard',
        20 => 'Dancer',
        4020 => 'Clown',
        4021 => 'Gypsy',
        4042 => 'Baby Bard',
        4043 => 'Baby Dancer',
        4068 => 'Minstrel',
        4069 => 'Wanderer',
        4075 => 'Minstrel+',
        4076 => 'Wanderer+',
        4104 => 'Baby Minstrel',
        4105 => 'Baby Wanderer',
        4211 => 'Kagerou',
        4212 => 'Oboro',
        4223 => 'Baby Kagerou',
        4224 => 'Baby Oboro',
        4263 => 'Troubadour',
        4264 => 'Trouvere',
    ],


    /*
    |--------------------------------------------------------------------------
    | Monster races, sizes and elements
    |--------------------------------------------------------------------------
    |
    | rAthena stores these two different ways depending on the table. The
    | pre-renewal `mob_db` holds a number; the renewal `mob_db_re`, generated
    | from the YAML, holds a word -- `Formless`, `Medium`, `Water`. Both forms
    | are keyed here so one lookup answers either, and a value that is neither
    | is reported as it was found rather than as zero.
    |
    | FluxCP's races.php, sizes.php and elements.php were word-keyed only,
    | which is why its numeric branch had to guess.
    |
    */

    'monster_races' => [
        0 => 'Formless',
        1 => 'Undead',
        2 => 'Brute',
        3 => 'Plant',
        4 => 'Insect',
        5 => 'Fish',
        6 => 'Demon',
        7 => 'Demi-Human',
        8 => 'Angel',
        9 => 'Dragon',

        'Formless' => 'Formless',
        'Undead' => 'Undead',
        'Brute' => 'Brute',
        'Plant' => 'Plant',
        'Insect' => 'Insect',
        'Fish' => 'Fish',
        'Demon' => 'Demon',
        'Demihuman' => 'Demi-Human',
        'Angel' => 'Angel',
        'Dragon' => 'Dragon',
    ],

    'monster_sizes' => [
        0 => 'Small',
        1 => 'Medium',
        2 => 'Large',

        'Small' => 'Small',
        'Medium' => 'Medium',
        'Large' => 'Large',
    ],

    /*
     * `mob_db` packs the element level into the same column: the stored value
     * is `element + level * 20`, so 23 is a level 1 Fire monster. Decoding
     * that is MonsterResource's job; this is the element table it decodes
     * against.
     */
    'monster_elements' => [
        0 => 'Neutral',
        1 => 'Water',
        2 => 'Earth',
        3 => 'Fire',
        4 => 'Wind',
        5 => 'Poison',
        6 => 'Holy',
        7 => 'Dark',
        8 => 'Ghost',
        9 => 'Undead',

        'Neutral' => 'Neutral',
        'Water' => 'Water',
        'Earth' => 'Earth',
        'Fire' => 'Fire',
        'Wind' => 'Wind',
        'Poison' => 'Poison',
        'Holy' => 'Holy',
        'Dark' => 'Dark',
        'Ghost' => 'Ghost',
        'Undead' => 'Undead',
    ],

    /*
    |--------------------------------------------------------------------------
    | Monster AI
    |--------------------------------------------------------------------------
    |
    | rAthena's `mob_db` AI numbers, each a shorthand for a set of mode flags.
    | Kept as the sets rather than as names, because the number is meaningless
    | to a reader and the modes are what it means.
    |
    */

    'monster_ai' => [
        1 => ['mode_canattack', 'mode_canmove'],
        2 => ['mode_canattack', 'mode_looter', 'mode_canmove'],
        3 => ['mode_changetargetmelee', 'mode_canattack', 'mode_assist', 'mode_canmove'],
        4 => ['mode_changetargetchase', 'mode_changetargetmelee', 'mode_angry', 'mode_canattack', 'mode_aggressive', 'mode_canmove'],
        5 => ['mode_changetargetchase', 'mode_canattack', 'mode_aggressive', 'mode_canmove'],
        6 => [],
        7 => ['mode_changetargetmelee', 'mode_canattack', 'mode_assist', 'mode_looter', 'mode_canmove'],
        8 => ['mode_targetweak', 'mode_changetargetchase', 'mode_changetargetmelee', 'mode_canattack', 'mode_aggressive', 'mode_canmove'],
        9 => ['mode_changetargetchase', 'mode_changetargetmelee', 'mode_canattack', 'mode_castsensoridle', 'mode_aggressive', 'mode_canmove'],
        10 => ['mode_canattack', 'mode_aggressive'],
        11 => ['mode_canattack', 'mode_aggressive'],
        12 => ['mode_changetargetchase', 'mode_canattack', 'mode_aggressive', 'mode_canmove'],
        13 => ['mode_changetargetchase', 'mode_changetargetmelee', 'mode_canattack', 'mode_assist', 'mode_aggressive', 'mode_canmove'],
        17 => ['mode_canattack', 'mode_castsensoridle', 'mode_canmove'],
        19 => ['mode_changetargetchase', 'mode_changetargetmelee', 'mode_canattack', 'mode_castsensoridle', 'mode_aggressive', 'mode_canmove'],
        20 => ['mode_changetargetchase', 'mode_changetargetmelee', 'mode_castsensorchase', 'mode_canattack', 'mode_castsensoridle', 'mode_aggressive', 'mode_canmove'],
        21 => ['mode_changetargetchase', 'mode_changetargetmelee', 'mode_changechase', 'mode_castsensorchase', 'mode_canattack', 'mode_castsensoridle', 'mode_aggressive', 'mode_canmove'],
        24 => ['mode_canattack', 'mode_norandomwalk', 'mode_canmove'],
        25 => ['mode_canmove'],
        26 => ['mode_randomtarget', 'mode_changetargetchase', 'mode_changetargetmelee', 'mode_changechase', 'mode_castsensorchase', 'mode_canattack', 'mode_castsensoridle', 'mode_aggressive', 'mode_canmove'],
        27 => ['mode_randomtarget', 'mode_canattack', 'mode_aggressive'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Item subtypes
    |--------------------------------------------------------------------------
    |
    | What an item's `subtype` column means, which depends on its `type`: the
    | same word means a throwing dagger on ammunition and a melee dagger on a
    | weapon. FluxCP's itemsubtypes.php, grouped the same way.
    |
    */

    'item_subtypes' => [
        'weapon' => [
            '1haxe' => 'One-Handed Axe',
            '1hspear' => 'One-Handed Spear',
            '1hsword' => 'One-Handed Sword',
            '2haxe' => 'Two-Handed Axe',
            '2hspear' => 'Two-Handed Spear',
            '2hstaff' => 'Two-Handed Staff',
            '2hsword' => 'Two-Handed Sword',
            'book' => 'Book',
            'bow' => 'Bow',
            'dagger' => 'Dagger',
            'gatling' => 'Gatling Gun',
            'grenade' => 'Grenade Launcher',
            'huuma' => 'Fuuma Shuriken',
            'katar' => 'Katar',
            'knuckle' => 'Knuckle',
            'mace' => 'Mace',
            'musical' => 'Musical Instrument',
            'revolver' => 'Revolver',
            'rifle' => 'Rifle',
            'shotgun' => 'Shotgun',
            'staff' => 'Staff',
            'whip' => 'Whip',
        ],
        'ammo' => [
            'arrow' => 'Arrow',
            'bullet' => 'Bullet',
            'dagger' => 'Throwing Dagger',
            'cannonball' => 'Cannonball',
            'grenade' => 'Grenade',
            'kunai' => 'Kunai',
            'shell' => 'Shell',
            'shuriken' => 'Shuriken',
            'throwweapon' => 'Throwable Item (Sling Item)',
        ],
        'card' => [
            'normal' => 'Card',
            'enchant' => 'Enchant',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log vocabularies
    |--------------------------------------------------------------------------
    |
    | The single characters rAthena writes into its log tables. Without these
    | a pick log reads `M` where it should read `Monster`, which is the column
    | an operator investigating a duplicated item looks at first.
    |
    */

    'pick_types' => [
        'A' => 'Admin',
        'B' => 'Buy Store',
        'C' => 'Consumed',
        'D' => 'Stolen/Ganked',
        'E' => 'Mailed',
        'F' => 'Bound Retrieval',
        'G' => 'Guild Storage',
        'I' => 'Auctioned',
        'K' => 'Bank',
        'L' => 'Looted',
        'M' => 'Monster',
        'N' => 'NPC (Script)',
        'O' => 'Produced',
        'P' => 'Player',
        'Q' => 'Quest',
        'R' => 'Storage',
        'S' => 'NPC (Shop)',
        'T' => 'Traded',
        'U' => 'MVP',
        'V' => 'Vended',
        'X' => 'Other',
        'Y' => 'Lottery',
        'Z' => 'Merged',
        '$' => 'Cash',
    ],

    'feeding_types' => [
        'P' => 'Pet',
        'H' => 'Homunculus',
        'O' => 'Other',
    ],

    /*
     * Why a control-panel sign-in was refused, as `cp_loginlog.error`.
     */
    'login_errors' => [
        0 => 'Unexpected Error',
        1 => 'Invalid Server',
        2 => 'Invalid Credentials',
        3 => 'Temporarily Banned',
        4 => 'Permanently Banned',
        5 => 'IP Banned',
        6 => 'Invalid Security Code',
        7 => 'Pending Confirmation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Equip location combinations
    |--------------------------------------------------------------------------
    |
    | Sets of location columns that mean one thing together. An item set in
    | both hands is two-handed, not "Right Hand, Left Hand", and listing the
    | columns separately describes the storage rather than the item.
    |
    | Each key is the location columns sorted and joined with a slash, so a
    | lookup does not depend on the order rAthena happens to return them in.
    | FluxCP's equip_location_combinations.php, with its keys already in that
    | form.
    |
    */

    'equip_location_combinations' => [
        'location_left_hand/location_right_hand' => 'Two-Handed',
        'location_head_low/location_head_mid/location_head_top' => 'Upper/Mid/Lower Headgear',
        'location_head_mid/location_head_top' => 'Upper/Mid Headgear',
        'location_head_low/location_head_top' => 'Upper/Lower Headgear',
        'location_head_low/location_head_mid' => 'Mid/Lower Headgear',
        'location_head_low/location_head_top' => 'Upper/Lower Headgear',
        'location_costume_head_mid/location_costume_head_top' => 'Costume Upper/Mid Headgear',
        'location_costume_head_low/location_costume_head_top' => 'Costume Upper/Lower Headgear',
        'location_costume_head_low/location_costume_head_mid' => 'Costume Mid/Lower Headgear',
        'location_costume_head_low/location_costume_head_mid/location_costume_head_top' => 'Costume Upper/Mid/Lower Headgear',
        'location_left_accessory/location_right_accessory' => 'Accessory Left/Right',
        'location_armor/location_garment/location_head_low/location_head_mid/location_head_top/location_left_accessory/location_left_hand/location_right_accessory/location_right_hand/location_shoes' => 'All equip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Item random options
    |--------------------------------------------------------------------------
    |
    | Renewal items carry up to five random options, stored as an id and a
    | value. The id alone says nothing, so these are the format strings the
    | value is put into -- FluxCP's item_randoptions.php, unchanged.
    |
    | `%s` is the stored value. A `%%` is a literal per cent sign.
    |
    */

    'item_random_options' => [
        1 => 'MaxHP +%s',
        2 => 'MaxSP +%s',
        3 => 'STR +%s',
        4 => 'AGI +%s',
        5 => 'VIT +%s',
        6 => 'INT +%s',
        7 => 'DEX +%s',
        8 => 'LUK +%s',
        9 => 'MaxHP +%s%%',
        10 => 'MaxSP +%s%%',
        11 => 'HP regen +%s%%',
        12 => 'SP regen +%s%%',
        13 => 'ATK +%s%%',
        14 => 'MATK +%s%%',
        15 => 'ASPD +%s',
        16 => 'Delay after attack -%s%%',
        17 => 'ATK +%s',
        18 => 'HIT +%s',
        19 => 'MATK +%s',
        20 => 'DEF +%s',
        21 => 'MDEF +%s',
        22 => 'FLEE +%s',
        23 => 'Perfect dodge +%s',
        24 => 'CRIT +%s',
        25 => 'Neutral elemental resistance +%s%%',
        26 => 'Water elemental resistance +%s%%',
        27 => 'Earth elemental resistance +%s%%',
        28 => 'Fire elemental resistance +%s%%',
        29 => 'Wind elemental resistance +%s%%',
        30 => 'Poison elemental resistance +%s%%',
        31 => 'Holy elemental resistance +%s%%',
        32 => 'Shadow elemental resistance +%s%%',
        33 => 'Ghost elemental resistance +%s%%',
        34 => 'Undead elemental resistance +%s%%',
        35 => 'All elementals resistance +%s%%',
        36 => 'Neutral monster resistance +%s%%',
        37 => 'ATK +%s%% against Neutral monster',
        38 => 'Water monster resistance +%s%%',
        39 => 'ATK +%s%% against Water monster',
        40 => 'Earth monster resistance +%s%%',
        41 => 'ATK +%s%% against Earth monster',
        42 => 'Fire monster resistance +%s%%',
        43 => 'ATK +%s%% against Fire monster',
        44 => 'Wind monster resistance +%s%%',
        45 => 'ATK +%s%% against Wind monster',
        46 => 'Poison monster resistance +%s%%',
        47 => 'ATK +%s%% against Poison monster',
        48 => 'Holy monster resistance +%s%%',
        49 => 'ATK +%s%% against Holy monster',
        50 => 'Shadow monster resistance +%s%%',
        51 => 'ATK +%s%% against Shadow monster',
        52 => 'Ghost monster resistance +%s%%',
        53 => 'ATK +%s%% against Ghost monster',
        54 => 'Undead monster resistance +%s%%',
        55 => 'ATK +%s%% against Undead monster',
        56 => 'Neutral monster magic resistance +%s%%',
        57 => 'MATK +%s%% against Neutral monster',
        58 => 'Water monster magic resistance +%s%%',
        59 => 'MATK +%s%% against Water monster',
        60 => 'Earth monster magic resistance +%s%%',
        61 => 'MATK +%s%% against  Earth monster',
        62 => 'Fire monster magic resistance +%s%%',
        63 => 'MATK +%s%% against Fire monster',
        64 => 'Wind monster magic resistance +%s%%',
        65 => 'MATK +%s%% against Wind monster',
        66 => 'Poison monster magic resistance +%s%%',
        67 => 'MATK +%s%% against Poison monster',
        68 => 'Holy monster magic resistance +%s%%',
        69 => 'MATK +%s%% against Holy monster',
        70 => 'Shadow monster magic resistance +%s%%',
        71 => 'MATK +%s%% against Shadow monster',
        72 => 'Ghost monster magic resistance +%s%%',
        73 => 'MATK +%s%% against Ghost monster',
        74 => 'Undead monster magic resistance +%s%%',
        75 => 'MATK +%s%% against Undead monster',
        76 => 'Armor element: Neutral',
        77 => 'Armor element: Water',
        78 => 'Armor element: Earth',
        79 => 'Armor element: Fire',
        80 => 'Armor element: Wind',
        81 => 'Armor element: Poison',
        82 => 'Armor element: Holy',
        83 => 'Armor element: Shadow',
        84 => 'Armor element: Ghost',
        85 => 'Armor element: Undead',
        86 => '',
        87 => 'Formless monster resistance +%s%%',
        88 => 'Undead monster resistance +%s%%',
        89 => 'Brute monster resistance +%s%%',
        90 => 'Plant monster resistance +%s%%',
        91 => 'Insect monster resistance +%s%%',
        92 => 'Fish monster resistance +%s%%',
        93 => 'Demon monster resistance +%s%%',
        94 => 'Demihuman monster resistance +%s%%',
        95 => 'Angel monster resistance +%s%%',
        96 => 'Dragon monster resistance +%s%%',
        97 => 'ATK +%s%% against Formless monster',
        98 => 'ATK +%s%% against Undead monster',
        99 => 'ATK +%s%% against Brute monster',
        100 => 'ATK +%s%% against Plant monster',
        101 => 'ATK +%s%% against Insect monster',
        102 => 'ATK +%s%% against Fish monster',
        103 => 'ATK +%s%% against Demon monster',
        104 => 'ATK +%s%% against Demihuman monster',
        105 => 'ATK +%s%% against Angel monster',
        106 => 'ATK +%s%% against Dragon monster',
        107 => 'MATK +%s%% against Formless monster',
        108 => 'MATK +%s%% against Undead monster',
        109 => 'MATK +%s%% against Brute monster',
        110 => 'MATK +%s%% against Plant monster',
        111 => 'MATK +%s%% against Insect monster',
        112 => 'MATK +%s%% against Fish monster',
        113 => 'MATK +%s%% against Devil monster',
        114 => 'MATK +%s%% against Demihuman monster',
        115 => 'MATK +%s%% against Angel monster',
        116 => 'MATK +%s%% against Dragon monster',
        117 => 'CRIT +%s against Formless monster',
        118 => 'CRIT +%s against Undead monster',
        119 => 'CRIT +%s against Brute monster',
        120 => 'CRIT +%s against Plant monster',
        121 => 'CRIT +%s against Insect monster',
        122 => 'CRIT +%s against Fish monster',
        123 => 'CRIT +%s against Demon monster',
        124 => 'CRIT +%s against Demihuman monster',
        125 => 'CRIT +%s against Angel monster',
        126 => 'CRIT +%s against Dragon monster',
        127 => 'Pierces %s%% DEF of Formless monster',
        128 => 'Pierces %s%% DEF of Undead monster',
        129 => 'Pierces %s%% DEF of Brute monster',
        130 => 'Pierces %s%% DEF of Plant monster',
        131 => 'Pierces %s%% DEF of Insect monster',
        132 => 'Pierces %s%% DEF of Fish monster',
        133 => 'Pierces %s%% DEF of Demon monster',
        134 => 'Pierces %s%% DEF of Demihuman monster',
        135 => 'Pierces %s%% DEF of Angel monster',
        136 => 'Pierces %s%% DEF of Dragon monster',
        137 => 'Pierces %s%% MDEF of Formless monster',
        138 => 'Pierces %s%% MDEF of Undead monster',
        139 => 'Pierces %s%% MDEF of Brute monster',
        140 => 'Pierces %s%% MDEF of Plant monster',
        141 => 'Pierces %s%% MDEF of Insect monster',
        142 => 'Pierces %s%% MDEF of Fish monster',
        143 => 'Pierces %s%% MDEF of Demon monster',
        144 => 'Pierces %s%% MDEF of Demihuman monster',
        145 => 'Pierces %s%% MDEF of Angel monster',
        146 => 'Pierces %s%% MDEF of Dragon monster',
        147 => 'ATK +%s%% against Normal monster',
        148 => 'ATK +%s%% against Boss monster',
        149 => 'Normal monster resistance +%s%%',
        150 => 'Boss monster resistance +%s%%',
        151 => 'MATK +%s%% against Normal monster',
        152 => 'MATK +%s%% against Boss monster',
        153 => 'Pierces %s%% DEF of Normal monster',
        154 => 'Pierces %s%% DEF of Boss monster',
        155 => 'Pierces %s%% MDEF of Normal monster',
        156 => 'Pierces %s%% MDEF of Boss monster',
        157 => 'ATK +%s%% against Small size monster',
        158 => 'ATK +%s%% against Medium size monster',
        159 => 'ATK +%s%% against Large size monster',
        160 => 'Small monster resistance +%s%%',
        161 => 'Medium monster resistance +%s%%',
        162 => 'Large monster resistance +%s%%',
        163 => 'Nullify weapon\'s damage size penalty',
        164 => 'Critical attack +%s%%',
        165 => 'Critical damage -%s%%',
        166 => 'Long range physical attack +%s%%',
        167 => 'Long range physical damage -%s%%',
        168 => 'Healing skills +%s%%',
        169 => 'Restoration gained from Healing skills +%s%%',
        170 => 'Variable cast time -%s%%',
        171 => 'After cast delay -%s%%',
        172 => 'Reduces SP cost by %s%%',
        173 => '',
        174 => '',
        175 => 'Weapon element: Neutral',
        176 => 'Weapon element: Water',
        177 => 'Weapon element: Earth',
        178 => 'Weapon element: Fire',
        179 => 'Weapon element: Wind',
        180 => 'Weapon element: Poison',
        181 => 'Weapon element: Holy',
        182 => 'Weapon element: Shadow',
        183 => 'Weapon element: Ghost',
        184 => 'Weapon element: Undead',
        185 => 'Indestructible in battle',
        186 => 'Indestructible in battle',
        187 => 'MATK against Small size monster +%s%%',
        188 => 'MATK against Medium size monster +%s%%',
        189 => 'MATK against Large size monster +%s%%',
        190 => 'Small monster magic resistance +%s%%',
        191 => 'Medium monster magic resistance +%s%%',
        192 => 'Large monster magic resistance +%s%%',
        193 => 'Elemental attacks resistance +%s%%',
        194 => 'Formless monster resistance +%s%%',
        195 => 'Undead monster resistance +%s%%',
        196 => 'Brute monster resistance +%s%%',
        197 => 'Plant monster resistance +%s%%',
        198 => 'Insect monster resistance +%s%%',
        199 => 'Fish monster resistance +%s%%',
        200 => 'Demon monster resistance +%s%%',
        201 => 'Demihuman monster resistance +%s%%',
        202 => 'Angel monster resistance +%s%%',
        203 => 'Dragon monster resistance +%s%%',
        204 => 'Long range physical attack +%s%%',
        205 => 'Long range physical damage -%s%%',
        206 => 'Demi-Human players resistance + %s%%',
        207 => 'Doram players resistance +%s%%',
        208 => 'ATK against Demi-Human players +%s%%',
        209 => 'ATK against Doram players +%s%%',
        210 => 'MATK against Demi-Human players +%s%%',
        211 => 'MATK against Doram players +%s%%',
        212 => 'Critical +%s for Demi-Human players',
        213 => 'Critical +%s for Doram players',
        214 => 'Pierces %s%% DEF of Demi-Human players',
        215 => 'Pierces %s%% DEF of Doram players',
        216 => 'Pierces %s%% MDEF of Demi-Human players',
        217 => 'Pierces %s%% MDEF of Doram players',
        218 => 'Recieved reflected damage -%s%%',
        219 => 'Melee physical damage +%s%%',
        220 => 'Melee physical damage -%s%%',
    ],

];
