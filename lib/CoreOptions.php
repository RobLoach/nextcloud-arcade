<?php

declare(strict_types=1);

namespace OCA\Arcade;

/**
 * A curated set of libretro core options, offered per core in the personal
 * settings and passed to RetroArch when a game starts.
 *
 * Every option keeps an empty value meaning "leave it to the core", so the
 * defaults of the cores stay in charge unless somebody picks otherwise.
 * Options a core does not know are ignored by RetroArch.
 */
class CoreOptions {
	/**
	 * core => list of options, each with the RetroArch key, a label, and
	 * the values to choose from with their labels.
	 */
	public const OPTIONS = [
		'fceumm' => [
			'fceumm_palette' => [
				'label' => 'Color palette',
				'values' => [
					'default' => 'FCEUmm default',
					'nintendo-vc' => 'Nintendo Virtual Console',
					'wavebeam' => 'Wavebeam',
					'nes-classic-fbx-fs' => 'NES Classic',
					'raw' => 'Raw',
				],
			],
			'fceumm_region' => [
				'label' => 'Region',
				'values' => [
					'Auto' => 'Auto',
					'NTSC' => 'NTSC',
					'PAL' => 'PAL',
					'Dendy' => 'Dendy',
				],
			],
			'fceumm_nospritelimit' => [
				'label' => 'Remove sprite limit',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			// Runs the processor twice per frame, which is what the games
			// that slow down when the screen fills up were short of. Which
			// half of the frame gets the extra cycles decides what breaks:
			// games disagree, so both are offered rather than guessed at.
			'fceumm_overclocking' => [
				'label' => 'Overclock',
				'values' => [
					'disabled' => 'Off',
					'2x-Postrender' => '2x Postrender',
					'2x-VBlank' => '2x VBlank',
				],
			],
			'fceumm_overscan_v' => [
				'label' => 'Crop vertical overscan',
				'values' => ['enabled' => 'Yes', 'disabled' => 'No'],
			],
			'fceumm_overscan_h' => [
				'label' => 'Crop horizontal overscan',
				'values' => ['enabled' => 'Yes', 'disabled' => 'No'],
			],
			'fceumm_ntsc_filter' => [
				'label' => 'NTSC video filter',
				'values' => [
					'disabled' => 'No',
					'composite' => 'Composite',
					'svideo' => 'S-Video',
					'rgb' => 'RGB',
					'monochrome' => 'Monochrome',
				],
			],
			'fceumm_turbo_enable' => [
				'label' => 'Turbo buttons',
				'values' => ['None' => 'Off', 'Player 1' => 'Player 1', 'Player 2' => 'Player 2', 'Both' => 'Both players'],
			],
			'fceumm_sndquality' => [
				'label' => 'Sound quality',
				'values' => ['Low' => 'Low', 'High' => 'High', 'Very High' => 'Very high'],
			],
		],
		'snes9x' => [
			'snes9x_region' => [
				'label' => 'Region',
				'values' => ['auto' => 'Auto', 'ntsc' => 'NTSC', 'pal' => 'PAL'],
			],
			'snes9x_overscan' => [
				'label' => 'Overscan',
				'values' => ['auto' => 'Auto', 'enabled' => 'Show', 'disabled' => 'Crop'],
			],
			'snes9x_reduce_sprite_flicker' => [
				'label' => 'Reduce sprite flicker',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'snes9x_hires_blend' => [
				'label' => 'Blend high resolution modes',
				'values' => ['disabled' => 'No', 'merge' => 'Merge', 'blur' => 'Blur'],
			],
			'snes9x_blargg' => [
				'label' => 'NTSC video filter',
				'values' => [
					'disabled' => 'No',
					'composite' => 'Composite',
					's-video' => 'S-Video',
					'rgb' => 'RGB',
					'monochrome' => 'Monochrome',
				],
			],
			'snes9x_audio_interpolation' => [
				'label' => 'Audio interpolation',
				'values' => ['gaussian' => 'Gaussian, as the hardware', 'cubic' => 'Cubic', 'sinc' => 'Sinc', 'linear' => 'Linear', 'none' => 'None'],
			],
			'snes9x_up_down_allowed' => [
				'label' => 'Allow opposite directions at once',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'snes9x_overclock_superfx' => [
				'label' => 'SuperFX overclock',
				'values' => ['100%' => 'None', '150%' => '150%', '200%' => '200%', '300%' => '300%'],
			],
			'snes9x_overclock_cycles' => [
				'label' => 'Reduce slowdown by overclocking',
				'values' => [
					'disabled' => 'No',
					'light' => 'Light',
					'compatible' => 'Compatible',
					'max' => 'Max, may break games',
				],
			],
		],
		'gambatte' => [
			'gambatte_gb_hwmode' => [
				'label' => 'Hardware mode',
				'values' => ['Auto' => 'Auto', 'GB' => 'Game Boy', 'GBC' => 'Game Boy Color', 'GBA' => 'Game Boy Advance'],
			],
			'gambatte_gb_colorization' => [
				'label' => 'Colorize Game Boy games',
				'values' => [
					'disabled' => 'No',
					'auto' => 'Auto',
					'internal' => 'Chosen palette below',
					'GBC' => 'Game Boy Color',
					'SGB' => 'Super Game Boy',
				],
			],
			'gambatte_gb_internal_palette' => [
				'label' => 'Palette for colorized games',
				'values' => [
					'GB - DMG' => 'Game Boy',
					'GB - Pocket' => 'Game Boy Pocket',
					'GB - Light' => 'Game Boy Light',
					'GBC - Blue' => 'Blue',
					'GBC - Brown' => 'Brown',
					'GBC - Green' => 'Green',
					'GBC - Red' => 'Red',
					'GBC - Yellow' => 'Yellow',
				],
			],
			'gambatte_gbc_color_correction' => [
				'label' => 'Game Boy Color color correction',
				'values' => ['disabled' => 'No', 'GBC only' => 'Game Boy Color games only', 'always' => 'Always'],
			],
			'gambatte_dark_filter_level' => [
				'label' => 'Darken the picture',
				'values' => ['0' => 'No', '10' => '10%', '25' => '25%', '50' => '50%'],
			],
			'gambatte_up_down_allowed' => [
				'label' => 'Allow opposite directions at once',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'gambatte_gb_bootloader' => [
				'label' => 'Show the start-up logo',
				'values' => ['enabled' => 'Yes', 'disabled' => 'No'],
			],
			'gambatte_mix_frames' => [
				'label' => 'Blend frames',
				'values' => [
					'disabled' => 'No',
					'accurate' => 'Accurate',
					'fast' => 'Fast',
					'lcd_ghosting' => 'LCD ghosting',
					'lcd_ghosting_fast' => 'LCD ghosting, fast',
				],
			],
		],
		'mgba' => [
			'mgba_gb_model' => [
				'label' => 'Game Boy model',
				'values' => [
					'Autodetect' => 'Auto',
					'Game Boy' => 'Game Boy',
					'Super Game Boy' => 'Super Game Boy',
					'Game Boy Color' => 'Game Boy Color',
					'Game Boy Advance' => 'Game Boy Advance',
				],
			],
			'mgba_use_bios' => [
				'label' => 'Use the BIOS when available',
				'values' => ['OFF' => 'No', 'ON' => 'Yes'],
			],
			'mgba_color_correction' => [
				'label' => 'Color correction',
				'values' => ['OFF' => 'No', 'GBA' => 'Game Boy Advance', 'GBC' => 'Game Boy Color', 'Auto' => 'Auto'],
			],
			'mgba_interframe_blending' => [
				'label' => 'Blend frames',
				'values' => [
					'OFF' => 'No',
					'mix' => 'Mix',
					'mix_smart' => 'Mix, smart',
					'lcd_ghosting' => 'LCD ghosting',
					'lcd_ghosting_fast' => 'LCD ghosting, fast',
				],
			],
			'mgba_frameskip' => [
				'label' => 'Frame skipping',
				'values' => ['disabled' => 'No', 'auto' => 'Auto'],
			],
			'mgba_skip_bios' => [
				'label' => 'Skip the BIOS intro',
				'values' => ['OFF' => 'No', 'ON' => 'Yes'],
			],
			'mgba_audio_low_pass_filter' => [
				'label' => 'Soften the audio',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'mgba_solar_sensor_level' => [
				'label' => 'Solar sensor level',
				'values' => ['0' => '0, darkest', '3' => '3', '5' => '5', '10' => '10, brightest'],
			],
		],
		'genesis_plus_gx' => [
			'genesis_plus_gx_region_detect' => [
				'label' => 'Region',
				'values' => ['auto' => 'Auto', 'ntsc-u' => 'US', 'pal' => 'Europe', 'ntsc-j' => 'Japan'],
			],
			'genesis_plus_gx_overscan' => [
				'label' => 'Borders',
				'values' => [
					'disabled' => 'Hide',
					'top/bottom' => 'Top and bottom',
					'left/right' => 'Left and right',
					'full' => 'All',
				],
			],
			'genesis_plus_gx_no_sprite_limit' => [
				'label' => 'Remove sprite limit',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'genesis_plus_gx_blargg_ntsc_filter' => [
				'label' => 'NTSC video filter',
				'values' => [
					'disabled' => 'No',
					'composite' => 'Composite',
					'svideo' => 'S-Video',
					'rgb' => 'RGB',
					'monochrome' => 'Monochrome',
				],
			],
			'genesis_plus_gx_addr_error' => [
				'label' => 'Strict address errors',
				'values' => ['enabled' => 'Yes, as the hardware', 'disabled' => 'No, helps ROM hacks'],
			],
			'genesis_plus_gx_ym2413' => [
				'label' => 'FM sound chip, Master System',
				'values' => ['auto' => 'Auto', 'disabled' => 'No', 'enabled' => 'Yes'],
			],
			'genesis_plus_gx_audio_filter' => [
				'label' => 'Audio filter',
				'values' => ['disabled' => 'No', 'low-pass' => 'Low pass'],
			],
			'genesis_plus_gx_lcd_filter' => [
				'label' => 'LCD ghosting filter, handhelds',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
		],
		'picodrive' => [
			'picodrive_region' => [
				'label' => 'Region',
				'values' => [
					'Auto' => 'Auto',
					'US' => 'US',
					'Europe' => 'Europe',
					'Japan NTSC' => 'Japan NTSC',
					'Japan PAL' => 'Japan PAL',
				],
			],
			'picodrive_sprlim' => [
				'label' => 'Remove sprite limit',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'picodrive_input1' => [
				'label' => 'Controller, player 1',
				'values' => ['3 button pad' => '3 button pad', '6 button pad' => '6 button pad'],
			],
			'picodrive_input2' => [
				'label' => 'Controller, player 2',
				'values' => ['3 button pad' => '3 button pad', '6 button pad' => '6 button pad'],
			],
			'picodrive_renderer' => [
				'label' => 'Renderer',
				'values' => ['accurate' => 'Accurate', 'good' => 'Good', 'fast' => 'Fast'],
			],
			'picodrive_audio_filter' => [
				'label' => 'Audio filter',
				'values' => ['off' => 'No', 'low-pass' => 'Low pass'],
			],
		],
		'mednafen_pce_fast' => [
			'pce_nospritelimit' => [
				'label' => 'Remove sprite limit',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'pce_ocmultiplier' => [
				'label' => 'Processor overclock',
				'values' => ['1' => 'None', '2' => '2x', '3' => '3x'],
			],
		],
		'handy' => [
			'handy_rot' => [
				'label' => 'Screen rotation',
				'values' => ['None' => 'None', '90' => '90°', '180' => '180°', '270' => '270°'],
			],
			'handy_frameskip' => [
				'label' => 'Frame skipping',
				'values' => ['disabled' => 'No', 'auto' => 'Auto'],
			],
			'handy_gfx_colors' => [
				'label' => 'Color depth',
				'values' => ['16bit' => '16 bit', '24bit' => '24 bit'],
			],
		],
		'mednafen_ngp' => [
			'ngp_language' => [
				'label' => 'Language',
				'values' => ['english' => 'English', 'japanese' => 'Japanese'],
			],
		],
		'mednafen_wswan' => [
			'wswan_rotate_display' => [
				'label' => 'Screen rotation',
				'values' => ['manual' => 'Manual', 'landscape' => 'Landscape', 'portrait' => 'Portrait'],
			],
		],
		'mednafen_vb' => [
			'vb_3dmode' => [
				'label' => '3D mode',
				'values' => [
					'anaglyph' => 'Anaglyph',
					'cyberscope' => 'CyberScope',
					'side-by-side' => 'Side by side',
					'vli' => 'Vertical line interlaced',
					'hli' => 'Horizontal line interlaced',
				],
			],
			'vb_anaglyph_preset' => [
				'label' => 'Anaglyph colors',
				'values' => [
					'disabled' => 'No',
					'red & blue' => 'Red and blue',
					'red & cyan' => 'Red and cyan',
					'red & electric cyan' => 'Red and electric cyan',
					'red & green' => 'Red and green',
					'green & magenta' => 'Green and magenta',
					'yellow & blue' => 'Yellow and blue',
				],
			],
		],
		'vecx' => [
			'vecx_res_multi' => [
				'label' => 'Resolution multiplier',
				'values' => ['1' => '1x', '2' => '2x', '3' => '3x', '4' => '4x'],
			],
		],
		'pcsx_rearmed' => [
			'pcsx_rearmed_region' => [
				'label' => 'Region',
				'values' => ['auto' => 'Auto', 'NTSC' => 'NTSC', 'PAL' => 'PAL'],
			],
			'pcsx_rearmed_neon_enhancement_enable' => [
				'label' => 'Enhanced resolution',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'pcsx_rearmed_neon_enhancement_no_main' => [
				'label' => 'Enhanced resolution speed hack',
				'values' => ['disabled' => 'No', 'enabled' => 'Yes'],
			],
			'pcsx_rearmed_dithering' => [
				'label' => 'Dithering, as the hardware',
				'values' => ['enabled' => 'Yes', 'disabled' => 'No'],
			],
			'pcsx_rearmed_frameskip_type' => [
				'label' => 'Frame skipping',
				'values' => [
					'disabled' => 'No',
					'auto' => 'Auto',
					'auto_threshold' => 'Auto, under load',
					'fixed_interval' => 'Fixed interval',
				],
			],
		],
		'gearcoleco' => [
			'gearcoleco_timing' => [
				'label' => 'Region',
				'values' => ['Auto' => 'Auto', 'NTSC (60 Hz)' => 'NTSC', 'PAL (50 Hz)' => 'PAL'],
			],
			'gearcoleco_no_sprite_limit' => [
				'label' => 'Remove sprite limit',
				'values' => ['Disabled' => 'No', 'Enabled' => 'Yes'],
			],
			'gearcoleco_up_down_allowed' => [
				'label' => 'Allow opposite directions at once',
				'values' => ['Disabled' => 'No', 'Enabled' => 'Yes'],
			],
		],
	];

	/**
	 * The systems each core runs, to label the settings.
	 *
	 * @return array<string, list<string>> core => system names
	 */
	public static function systemsByCore(): array {
		$systems = [];
		foreach (CoreMap::SYSTEMS as $system) {
			$systems[$system['core']][] = $system['short'];
		}
		return $systems;
	}
}
