<?php
/**
 * Languages offered by the voice widget.
 *
 * Codes are BCP-47 tags understood by the browser Web Speech API (speech recognition and
 * speech synthesis). Which languages actually work for voice depends on the visitor's browser
 * and device; typed chat works in every language.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Language helper.
 */
class AIVA_Languages {

	/**
	 * Languages enabled on a fresh install (the original five).
	 *
	 * @var array
	 */
	private static $defaults = array( 'en-US', 'es-ES', 'fr-FR', 'de-DE', 'it-IT' );

	/**
	 * All supported languages.
	 *
	 * @return array code => array( 'native' => ..., 'english' => ..., 'rtl' => bool )
	 */
	public static function all() {
		$languages = array(
			'en-US'  => array( 'English', 'English (US)', false ),
			'en-GB'  => array( 'English (UK)', 'English (UK)', false ),
			'en-IN'  => array( 'English (India)', 'English (India)', false ),
			'en-AU'  => array( 'English (Australia)', 'English (Australia)', false ),
			'es-ES'  => array( 'Español', 'Spanish (Spain)', false ),
			'es-MX'  => array( 'Español (México)', 'Spanish (Mexico)', false ),
			'fr-FR'  => array( 'Français', 'French', false ),
			'fr-CA'  => array( 'Français (Canada)', 'French (Canada)', false ),
			'de-DE'  => array( 'Deutsch', 'German', false ),
			'it-IT'  => array( 'Italiano', 'Italian', false ),
			'pt-BR'  => array( 'Português (Brasil)', 'Portuguese (Brazil)', false ),
			'pt-PT'  => array( 'Português', 'Portuguese (Portugal)', false ),
			'nl-NL'  => array( 'Nederlands', 'Dutch', false ),
			'pl-PL'  => array( 'Polski', 'Polish', false ),
			'ro-RO'  => array( 'Română', 'Romanian', false ),
			'cs-CZ'  => array( 'Čeština', 'Czech', false ),
			'sk-SK'  => array( 'Slovenčina', 'Slovak', false ),
			'hu-HU'  => array( 'Magyar', 'Hungarian', false ),
			'el-GR'  => array( 'Ελληνικά', 'Greek', false ),
			'bg-BG'  => array( 'Български', 'Bulgarian', false ),
			'hr-HR'  => array( 'Hrvatski', 'Croatian', false ),
			'sr-RS'  => array( 'Српски', 'Serbian', false ),
			'uk-UA'  => array( 'Українська', 'Ukrainian', false ),
			'ru-RU'  => array( 'Русский', 'Russian', false ),
			'sv-SE'  => array( 'Svenska', 'Swedish', false ),
			'da-DK'  => array( 'Dansk', 'Danish', false ),
			'nb-NO'  => array( 'Norsk', 'Norwegian', false ),
			'fi-FI'  => array( 'Suomi', 'Finnish', false ),
			'tr-TR'  => array( 'Türkçe', 'Turkish', false ),
			'ar-SA'  => array( 'العربية', 'Arabic', true ),
			'he-IL'  => array( 'עברית', 'Hebrew', true ),
			'fa-IR'  => array( 'فارسی', 'Persian', true ),
			'ur-PK'  => array( 'اردو', 'Urdu', true ),
			'hi-IN'  => array( 'हिन्दी', 'Hindi', false ),
			'bn-BD'  => array( 'বাংলা', 'Bengali', false ),
			'pa-IN'  => array( 'ਪੰਜਾਬੀ', 'Punjabi', false ),
			'gu-IN'  => array( 'ગુજરાતી', 'Gujarati', false ),
			'mr-IN'  => array( 'मराठी', 'Marathi', false ),
			'ta-IN'  => array( 'தமிழ்', 'Tamil', false ),
			'te-IN'  => array( 'తెలుగు', 'Telugu', false ),
			'kn-IN'  => array( 'ಕನ್ನಡ', 'Kannada', false ),
			'ml-IN'  => array( 'മലയാളം', 'Malayalam', false ),
			'id-ID'  => array( 'Bahasa Indonesia', 'Indonesian', false ),
			'ms-MY'  => array( 'Bahasa Melayu', 'Malay', false ),
			'fil-PH' => array( 'Filipino', 'Filipino', false ),
			'th-TH'  => array( 'ไทย', 'Thai', false ),
			'vi-VN'  => array( 'Tiếng Việt', 'Vietnamese', false ),
			'zh-CN'  => array( '中文 (简体)', 'Chinese (Mandarin, Simplified)', false ),
			'zh-TW'  => array( '中文 (繁體)', 'Chinese (Mandarin, Traditional)', false ),
			'zh-HK'  => array( '粵語', 'Chinese (Cantonese)', false ),
			'ja-JP'  => array( '日本語', 'Japanese', false ),
			'ko-KR'  => array( '한국어', 'Korean', false ),
			'sw-KE'  => array( 'Kiswahili', 'Swahili', false ),
			'af-ZA'  => array( 'Afrikaans', 'Afrikaans', false ),
		);

		$out = array();
		foreach ( $languages as $code => $data ) {
			$out[ $code ] = array(
				'native'  => $data[0],
				'english' => $data[1],
				'rtl'     => $data[2],
			);
		}

		/**
		 * Filter the list of languages available to the voice widget.
		 *
		 * @param array $out code => array( 'native' => ..., 'english' => ..., 'rtl' => bool ).
		 */
		return (array) apply_filters( 'aiva_languages', $out );
	}

	/**
	 * Codes enabled by the site owner (always includes the default language).
	 *
	 * @return array
	 */
	public static function enabled_codes() {
		$all     = self::all();
		$enabled = get_option( 'aiva_widget_languages', false );
		if ( ! is_array( $enabled ) ) {
			$enabled = self::$defaults;
		}
		$enabled = array_values( array_intersect( $enabled, array_keys( $all ) ) );

		$default = self::default_code();
		if ( ! in_array( $default, $enabled, true ) ) {
			array_unshift( $enabled, $default );
		}
		return $enabled;
	}

	/**
	 * Default language code.
	 *
	 * @return string
	 */
	public static function default_code() {
		$all     = self::all();
		$default = (string) get_option( 'aiva_widget_default_language', 'en-US' );
		return isset( $all[ $default ] ) ? $default : 'en-US';
	}

	/**
	 * Resolve a code sent by the browser to an enabled language (falls back to the default).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function resolve( $code ) {
		$code = (string) $code;
		return in_array( $code, self::enabled_codes(), true ) ? $code : self::default_code();
	}

	/**
	 * English name for the AI prompt, e.g. "Urdu (اردو)".
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function prompt_name( $code ) {
		$all = self::all();
		if ( ! isset( $all[ $code ] ) ) {
			return 'English';
		}
		$lang = $all[ $code ];
		return ( $lang['english'] === $lang['native'] ) ? $lang['english'] : $lang['english'] . ' (' . $lang['native'] . ')';
	}

	/**
	 * Sanitize the enabled-languages setting.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize_codes( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$all = array_keys( self::all() );
		$out = array();
		foreach ( $value as $code ) {
			$code = is_scalar( $code ) ? (string) $code : '';
			if ( in_array( $code, $all, true ) ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize the default language setting.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_code( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return array_key_exists( $value, self::all() ) ? $value : 'en-US';
	}
}
