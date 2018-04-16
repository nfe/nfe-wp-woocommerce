<?php

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists('NFe_Woo') ) :

	/**
	 * WooCommerce NFe NFe_Woo Class
	 *
	 * @author   NFe.io
	 * @package  WooCommerce_NFe/Class/NFe_Woo
	 * @version  1.0.7
	 */
	class NFe_Woo {

		/**
		* WC_Logger Logger instance
		*
		* @var boolean
		*/
		public static $logger = false;

		/**
		 * NFe_Woo Instance.
		 */
		public static function instance() {
			// Store the instance locally to avoid private static replication
			static $instance = null;

			// Only run these methods if they haven't been run previously
			if ( null === $instance ) {
				$instance = new NFe_Woo();
			}
			return $instance; // Always return the instance
		}

		/**
		 * Construct.
		 *
		 * @see $this->instance Class Instance
		 */
		private function __construct() {}

		/**
		 * Issue a NFe invoice.
		 *
		 * @param  array  $order_ids Orders to issue the NFe
		 *
		 * @return bool|string
		 */
		public function issue_invoice( $order_ids = array() ) {
			$key               = nfe_get_field('api_key');
			$company_id        = nfe_get_field('choose_company');
			$issue_when_status = nfe_get_field('issue_when_status');

			NFe::setApiKey($key);

			foreach ( $order_ids as $order_id ) {

				$order = nfe_wc_get_order( $order_id );

				$log = sprintf( __( 'NFe issuing process started! Order: #%d', 'woo-nfe' ), $order_id );
				$this->logger( $log );
				$order->add_order_note( $log );

				// If value is 0, don't issue it.
				if ( '0.00' === $order->get_total() ) {
					$log = sprintf( __( 'Not possible to issue NFe without an order value! Order: #%d', 'woo-nfe' ), $order_id );
					$this->logger( $log );
					$order->add_order_note( $log );

					return false;
				}

				$datainvoice = $this->order_info( $order_id );

				// Check if there was a problem on fetch the city code from IBGE using the postal code.
				if ( empty( $datainvoice['borrower']['address']['city']['code'] ) )	{
					$log = __( 'There was a problem fetching IBGE code! Check your CEP information.', 'woo-nfe' );
					$this->logger( $log );
					$order->add_order_note( $log );
				}

				$invoice = NFe_ServiceInvoice::create( $company_id, $datainvoice );

				if ( isset( $invoice->message ) ) {
					$log = sprintf( __( 'An error occurred while issuing a NFe: %s', 'woo-nfe' ), print_r( $invoice->message, true ) );
					$this->logger( $log );
					$order->add_order_note( $log );

					return false;
				}

				$log = sprintf( __( 'NFe sent sucessfully to issue! Order: #%d', 'woo-nfe' ), $order_id );
				$this->logger( $log );
				$order->add_order_note( $log );

				$nfe = array(
					'id'        => $invoice->id,
					'status'    => $invoice->flowStatus,
					'issuedOn'  => $invoice->issuedOn,
					'amountNet' => $invoice->amountNet,
					'checkCode' => $invoice->checkCode,
					'number'    => $invoice->number,
				);
				update_post_meta( $order_id, 'nfe_issued', $nfe );
			}

			return $invoice;
		}

		/**
		 * Downloads the invoice(s).
		 *
		 * @param  array  $order_ids Array of order ids.
		 * @return string            Pdf url from NFe.io
		 */
		public function download_pdf_invoice( $order_ids = array() ) {
			$key 		= nfe_get_field('api_key');
			$company_id = nfe_get_field('choose_company');

			NFe::setApiKey($key);

			foreach ( $order_ids as $order_id ) {
				$nfe   = get_post_meta( $order_id, 'nfe_issued', true );
				$order = nfe_wc_get_order($order_id);

				try {
					$pdf = NFe_ServiceInvoice::pdf( $company_id, $nfe['id'] );

					$log = sprintf( __( 'NFe PDF Donwload successfully. Order: #%d', 'woo-nfe' ), $order_id );
					$this->logger( $log );
					$order->add_order_note( $log );
				} catch ( Exception $e ) {
					$log = sprintf( __( 'There was a problem when trying to download NFe PDF! Error: %s', 'woo-nfe' ), print_r( $e->getMessage(), true ) );
					$this->logger( $log );
					$order->add_order_note( $log );

					throw new Exception( $log );

					return false;
				}
			}

			return $pdf;
		}

		/**
		 * Preparing data to send to NFe API
		 *
		 * @param int $order_id Order ID.
		 *
		 * @return array Array with the order_id information to issue the invoice
		 */
		public function order_info( $order_id ) {

			$total = nfe_wc_get_order( $order_id );

			$data = array(
				'cityServiceCode' 			=> $this->city_service_info( 'code', $order_id ),
				'federalServiceCode'		=> $this->city_service_info( 'fed_code', $order_id ),
				'description' 				=> $this->remover_caracter( $this->city_service_info( 'desc', $order_id ) ),
				'servicesAmount' 			=> $total->get_total(),
				'borrower' 					=> array(
					'name' 						=> $this->remover_caracter( $this->check_customer_info( 'name', $order_id ) ),
					'email' 					=> get_post_meta( $order_id, '_billing_email', true ),
					'federalTaxNumber'			=> $this->removepontotraco( $this->check_customer_info( 'number', $order_id ) ),
					'address' 				=> array(
						'postalCode' 			=> $this->cep( get_post_meta( $order_id, '_billing_postcode', true ) ),
						'street' 				=> $this->remover_caracter( $this->check_customer_info( 'street', $order_id ) ),
						'number' 				=> $this->remover_caracter( $this->check_customer_info( 'address_number', $order_id ) ),
						'additionalInformation' => $this->remover_caracter( get_post_meta( $order_id, '_billing_address_2', true ) ),
						'district' 				=> $this->remover_caracter( $this->check_customer_info( 'district', $order_id ) ),
						'country' 				=> $this->remover_caracter( $this->billing_country( $order_id ) ),
						'state' 				=> $this->remover_caracter( $this->check_customer_info( 'state', $order_id ) ),
						'city' 					=> array(
							'code' 			=> $this->ibge_code( $order_id ),
							'name' 			=> $this->remover_caracter( $this->check_customer_info( 'city', $order_id ) ),
						),
					),
				),
			);

			// Removes empty, false and null fields from the array.
			return array_filter( $data );
		}

		/**
		 * Hack to bring support to Brazilian ISO code (Ex.: BRA instead of BR)
		 *
		 * @param int $order_id Product ID.
		 *
		 * @return string
		 */
		protected function billing_country( $order_id ) {
			$country = get_post_meta( $order_id, '_billing_country', true );

			if ( empty( $country ) ) {
				$country = 'BR';
			}

			$countries = $this->country_iso_codes();

			foreach ( $countries as $iso3 => $iso2 ) {
				if ( $country === $iso2 ) {
					$c = $iso3;
				}
			}

			return $c;
		}

		/**
		 * Fetches the IBGE Code
		 *
		 * @param  int $order_id Order ID.
		 * @return string
		 */
		protected function ibge_code( $order_id ) {
			if ( empty( $order_id ) ) {
				return;
			}

			$key = nfe_get_field( 'api_key' );
			$post_code = get_post_meta( $order_id, '_billing_postcode', true );

			if ( empty( $post_code ) ) {
				return;
			}

			$url      = 'https://open.nfe.io/v1/addresses/' . $post_code . '?api_key=' . $key;
			$response = wp_remote_get( esc_url_raw( $url ) );

			if ( is_wp_error( $response ) ) {
				return;
			}

			$address = json_decode( wp_remote_retrieve_body( $response ), true );

			return $address['city']['code'];
		}

		/**
		 * City Service Information (City and Federal Code, and Description).
		 *
		 * @param string $field   The field info being fetched.
		 * @param int    $post_id Post ID.
		 *
		 * @return string
		 */
		protected function city_service_info( $field = '', $post_id ) {
			if ( empty( $field ) ) {
				return;
			}

			$order = nfe_wc_get_order( $post_id );

			if ( 0 < count( $order->get_items() ) ) {
				// Variations or Simple Product Info.
				foreach ( $order->get_items() as $key => $item ) {
					$product_id   = $item['product_id'];
					$variation_id = $item['variation_id'];

					if ( $variation_id ) {
						$cityservicecode    = get_post_meta( $variation_id, '_cityservicecode', true );
						$federalservicecode = get_post_meta( $variation_id, '_federalservicecode', true );
						$product_desc       = get_post_meta( $variation_id, '_nfe_product_variation_desc', true );
					} else {
						$cityservicecode    = get_post_meta( $product_id, '_simple_cityservicecode', true );
						$federalservicecode = get_post_meta( $product_id, '_simple_federalservicecode', true );
						$product_desc       = get_post_meta( $product_id, '_simple_nfe_product_desc', true );
					}
				}
			}

			switch ( $field ) {
				case 'code':
					$output = $cityservicecode ?: nfe_get_field( 'nfe_cityservicecode' );
					break;

				case 'fed_code':
					$output = $federalservicecode ?: nfe_get_field( 'nfe_fedservicecode' );
					break;

				case 'desc':
					$output = $product_desc ?: nfe_get_field( 'nfe_cityservicecode_desc' );
					break;

				default:
					$output = null;
					break;
			}

			return $output;
		}

		/**
		 * Fetching customer info depending on the person type.
		 *
		 * @param string $field Field to fetch info from.
		 * @param int    $order The order ID.
		 *
		 * @return string|empty Returns the customer info specific to the person type being fetched.
		 */
		protected function check_customer_info( $field = '', $order ) {

			if ( empty( $field ) ) {
				return;
			}

			// Customer Person Type.
			(int) $type = get_post_meta( $order, '_billing_persontype', true );

			switch ( $field ) {
				case 'number': // Customer ID Number.
					if ( $type == 1 || empty($type) ) {
						$output = $this->cpf( get_post_meta( $order, '_billing_cpf', true ) );
					} elseif ( $type == 2 || empty($type) || empty($output) ) {
						$output = $this->cnpj( get_post_meta( $order, '_billing_cnpj', true ) );
					}
					break;

				case 'name': // Customer Name/Razão Social.
					if ( $type == 1 || empty($type) ) {
						$output = get_post_meta( $order, '_billing_first_name', true ) . ' ' . get_post_meta( $order, '_billing_last_name', true );
					} elseif ( $type == 2 || empty($type) || empty($output) ) {
						$output = get_post_meta( $order, '_billing_company', true );
					}
					break;

				case 'type': // Customer Type.
					if ( 1 === $type || empty( $type ) ) {
						$output = __( 'Customers', 'woo-nfe' );
					} elseif ( 2 === $type ) {
						$output = __( 'Company', 'woo-nfe' );
					}
					break;

				case 'city':
					$output = get_post_meta( $order, '_billing_city', true );
					$output ?: $this->get_company_info( 'city' );
					break;

				case 'state':
					$output = get_post_meta( $order, '_billing_state', true );
					$output ?: $this->get_company_info( 'state' );
					break;

				case 'district':
					$output = get_post_meta( $order, '_billing_neighborhood', true );
					$output ?: __( 'Not Informed', 'woo-nfe' );
					break;

				case 'address_number':
					$output = get_post_meta( $order, '_billing_number', true );
					$output ?: __( 'SN', 'woo-nfe' );
					break;

				case 'street':
					$output = get_post_meta( $order, '_billing_address_1', true );
					$output ?: __( 'Street Not informed', 'woo-nfe' );
					break;

				default:
					$output = null;
					break;
			}

			return $output;
		}

		/**
		 * Get current company info.
		 *
		 * @param  string $field Field.
		 *
		 * @return string
		 */
		protected function get_company_info( $field ) {

			// Get info.
			$key        = nfe_get_field( 'api_key' );
			$company_id = nfe_get_field( 'choose_company' );

			$url        = 'https://open.nfe.io/v1/companies/' . $company_id . '?api_key=' . $key;
			$response   = wp_remote_get( esc_url_raw( $url ) );

			if ( is_wp_error( $response ) ) {
				return;
			}

			$company = json_decode( wp_remote_retrieve_body( $response ), true );

			return $company['companies']['address'][ $field ];
		}

		/**
		 * CPF Converter
		 *
		 * @param  string $cpf
		 * @return void
		 */
		protected function cpf( $cpf ) {
			if ( ! $cpf ) {
				return;
			}

			$cpf = $this->clear( $cpf );
			$cpf = $this->mask($cpf,'###.###.###-##');

			return $cpf;
		}

		/**
		 * CNPJ Converter
		 *
		 * @param  $cnpj
		 * @return string
		 */
		protected function cnpj( $cnpj ) {
			if ( ! $cnpj ) {
				return;
			}

			$cnpj = $this->clear( $cnpj );
			$cnpj = $this->mask($cnpj,'##.###.###/####-##');

			return $cnpj;
		}

		/**
		 * CEP Converter
		 *
		 * @param  $cep
		 * @return string
		 */
		protected function cep( $cep ) {
			if ( ! $cep ) {
				return;
			}

			$cep = $this->clear( $cep );
			$cep = $this->mask($cep,'#####-###');

			return $cep;
		}

		/**
		 * Clears
		 *
		 * @param  string $string
		 * @return string
		 */
		protected function clear( $string ) {
			$string = str_replace( array(',', '-', '!', '.', '/', '?', '(', ')', ' ', '$', 'R$', '€'), '', $string );

			return $string;
		}

		/**
		 * Masking
		 *
		 * @param  $val  Value that's gonna be masked
		 * @param  $mask Mask pattern
		 * @return string
		 */
		protected function mask( $val, $mask ) {
		   $maskared = '';
		   $k 		 = 0;

			for( $i = 0; $i <= strlen($mask) - 1; $i++ ) {
				if ( $mask[$i] == '#' ) {
					if ( isset($val[$k]) ) {
					   $maskared .= $val[$k++];
					}

			   } elseif ( isset($mask[$i]) ) {
					$maskared .= $mask[$i];
			   }
			}

			return $maskared;
		}

		protected function removepontotraco( $string ) {
			$string = preg_replace( "/[^0-9]/", "", $string );

			return ltrim( $string, '0' );
		}

		protected function remover_caracter( $string ) {
			$string = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron);~i', '$1', htmlentities($string, ENT_COMPAT, 'UTF-8'));
			$string = preg_replace("/[][><}{)(:;,!?*%~^`´&#@ªº°$¨]/", "", $string );
			return $string;
		}

		/**
		 * Country 2 and 3 ISO Codes
		 *
		 * @return array
		 */
		protected function country_iso_codes() {
			$iso_codes = array(
			   'AFG' => 'AF',     // Afghanistan
			   'ALB' => 'AL',     // Albania
			   'ARE' => 'AE',     // U.A.E.
			   'ARG' => 'AR',     // Argentina
			   'ARM' => 'AM',     // Armenia
			   'AUS' => 'AU',     // Australia
			   'AUT' => 'AT',     // Austria
			   'AZE' => 'AZ',     // Azerbaijan
			   'BEL' => 'BE',     // Belgium
			   'BGD' => 'BD',     // Bangladesh
			   'BGR' => 'BG',     // Bulgaria
			   'BHR' => 'BH',     // Bahrain
			   'BIH' => 'BA',     // Bosnia and Herzegovina
			   'BLR' => 'BY',     // Belarus
			   'BLZ' => 'BZ',     // Belize
			   'BOL' => 'BO',     // Bolivia
			   'BRA' => 'BR',     // Brazil
			   'BRN' => 'BN',     // Brunei Darussalam
			   'CAN' => 'CA',     // Canada
			   'CHE' => 'CH',     // Switzerland
			   'CHL' => 'CL',     // Chile
			   'CHN' => 'CN',     // People's Republic of China
			   'COL' => 'CO',     // Colombia
			   'CRI' => 'CR',     // Costa Rica
			   'CZE' => 'CZ',     // Czech Republic
			   'DEU' => 'DE',     // Germany
			   'DNK' => 'DK',     // Denmark
			   'DOM' => 'DO',     // Dominican Republic
			   'DZA' => 'DZ',     // Algeria
			   'ECU' => 'EC',     // Ecuador
			   'EGY' => 'EG',     // Egypt
			   'ESP' => 'ES',     // Spain
			   'EST' => 'EE',     // Estonia
			   'ETH' => 'ET',     // Ethiopia
			   'FIN' => 'FI',     // Finland
			   'FRA' => 'FR',     // France
			   'FRO' => 'FO',     // Faroe Islands
			   'GBR' => 'GB',     // United Kingdom
			   'GEO' => 'GE',     // Georgia
			   'GRC' => 'GR',     // Greece
			   'GRL' => 'GL',     // Greenland
			   'GTM' => 'GT',     // Guatemala
			   'HKG' => 'HK',     // Hong Kong S.A.R.
			   'HND' => 'HN',     // Honduras
			   'HRV' => 'HR',     // Croatia
			   'HUN' => 'HU',     // Hungary
			   'IDN' => 'ID',     // Indonesia
			   'IND' => 'IN',     // India
			   'IRL' => 'IE',     // Ireland
			   'IRN' => 'IR',     // Iran
			   'IRQ' => 'IQ',     // Iraq
			   'ISL' => 'IS',     // Iceland
			   'ISR' => 'IL',     // Israel
			   'ITA' => 'IT',     // Italy
			   'JAM' => 'JM',     // Jamaica
			   'JOR' => 'JO',     // Jordan
			   'JPN' => 'JP',     // Japan
			   'KAZ' => 'KZ',     // Kazakhstan
			   'KEN' => 'KE',     // Kenya
			   'KGZ' => 'KG',     // Kyrgyzstan
			   'KHM' => 'KH',     // Cambodia
			   'KOR' => 'KR',     // Korea
			   'KWT' => 'KW',     // Kuwait
			   'LAO' => 'LA',     // Lao P.D.R.
			   'LBN' => 'LB',     // Lebanon
			   'LBY' => 'LY',     // Libya
			   'LIE' => 'LI',     // Liechtenstein
			   'LKA' => 'LK',     // Sri Lanka
			   'LTU' => 'LT',     // Lithuania
			   'LUX' => 'LU',     // Luxembourg
			   'LVA' => 'LV',     // Latvia
			   'MAC' => 'MO',     // Macao S.A.R.
			   'MAR' => 'MA',     // Morocco
			   'MCO' => 'MC',     // Principality of Monaco
			   'MDV' => 'MV',     // Maldives
			   'MEX' => 'MX',     // Mexico
			   'MKD' => 'MK',     // Macedonia (FYROM)
			   'MLT' => 'MT',     // Malta
			   'MNE' => 'ME',     // Montenegro
			   'MNG' => 'MN',     // Mongolia
			   'MYS' => 'MY',     // Malaysia
			   'NGA' => 'NG',     // Nigeria
			   'NIC' => 'NI',     // Nicaragua
			   'NLD' => 'NL',     // Netherlands
			   'NOR' => 'NO',     // Norway
			   'NPL' => 'NP',     // Nepal
			   'NZL' => 'NZ',     // New Zealand
			   'OMN' => 'OM',     // Oman
			   'PAK' => 'PK',     // Islamic Republic of Pakistan
			   'PAN' => 'PA',     // Panama
			   'PER' => 'PE',     // Peru
			   'PHL' => 'PH',     // Republic of the Philippines
			   'POL' => 'PL',     // Poland
			   'PRI' => 'PR',     // Puerto Rico
			   'PRT' => 'PT',     // Portugal
			   'PRY' => 'PY',     // Paraguay
			   'QAT' => 'QA',     // Qatar
			   'ROU' => 'RO',     // Romania
			   'RUS' => 'RU',     // Russia
			   'RWA' => 'RW',     // Rwanda
			   'SAU' => 'SA',     // Saudi Arabia
			   'SCG' => 'CS',     // Serbia and Montenegro (Former)
			   'SEN' => 'SN',     // Senegal
			   'SGP' => 'SG',     // Singapore
			   'SLV' => 'SV',     // El Salvador
			   'SRB' => 'RS',     // Serbia
			   'SVK' => 'SK',     // Slovakia
			   'SVN' => 'SI',     // Slovenia
			   'SWE' => 'SE',     // Sweden
			   'SYR' => 'SY',     // Syria
			   'TAJ' => 'TJ',     // Tajikistan
			   'THA' => 'TH',     // Thailand
			   'TKM' => 'TM',     // Turkmenistan
			   'TTO' => 'TT',     // Trinidad and Tobago
			   'TUN' => 'TN',     // Tunisia
			   'TUR' => 'TR',     // Turkey
			   'TWN' => 'TW',     // Taiwan
			   'UKR' => 'UA',     // Ukraine
			   'URY' => 'UY',     // Uruguay
			   'USA' => 'US',     // United States
			   'UZB' => 'UZ',     // Uzbekistan
			   'VEN' => 'VE',     // Bolivarian Republic of Venezuela
			   'VNM' => 'VN',     // Vietnam
			   'YEM' => 'YE',     // Yemen
			   'ZAF' => 'ZA',     // South Africa
			   'ZWE' => 'ZW',     // Zimbabwe
			);

			return $iso_codes;
		}

		/**
	     * Logging method.
	     *
	     * @param string $message
	     */
	    public static function logger( $message ) {
	        if ( nfe_get_field('debug') == 'yes' ) {
	            if ( empty( self::$logger ) ) {
	                self::$logger = new WC_Logger();
	            }
	            self::$logger->add( 'nfe_api', $message );
	        }
	    }
	}

	/**
	 * The main function responsible for returning the one true NFe_Woo Instance.
	 *
	 * @since 1.0.0
	 *
	 * @return NFe_Woo The one true NFe_Woo Instance.
	 */
	function NFe_Woo() {
		return NFe_Woo::instance();
	}

endif;
