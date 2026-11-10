<?php defined( 'ABSPATH' ) OR die( 'This script cannot be accessed directly.' );

/**
 * Theme functions and definitions
 */

if ( ! defined( 'US_ACTIVATION_THEMENAME' ) ) {
	define( 'US_ACTIVATION_THEMENAME', 'Impreza' );
}

function custom_styles() {
    wp_enqueue_style( 
        'impreza-style', 
        get_stylesheet_directory_uri() . '/style.css', 
        array(), 
        '1.0', 
        'all' 
    );
}
add_action( 'wp_enqueue_scripts', 'custom_styles' );

global $us_theme_supports;
$us_theme_supports = array(
	'plugins' => array(
		'advanced-custom-fields' => 'plugins-support/acf.php',
		'bbpress' => 'plugins-support/bbpress.php',
		'contact-form-7' => NULL,
		'filebird' => 'plugins-support/filebird.php',
		'gravityforms' => 'plugins-support/gravityforms.php',
		'js_composer' => 'plugins-support/js_composer/js_composer.php',
		'post_views_counter' => 'plugins-support/post_views_counter.php',
		'revslider' => 'plugins-support/revslider.php',
		'tablepress' => 'plugins-support/tablepress.php',
		'the-events-calendar' => 'plugins-support/the_events_calendar.php',
		'tiny_mce' => 'plugins-support/tiny_mce.php',
		'Ultimate_VC_Addons' => 'plugins-support/Ultimate_VC_Addons.php',
		'woocommerce' => 'plugins-support/woocommerce.php',
		'woocommerce-germanized' => 'plugins-support/woocommerce-germanized.php',
		'woocommerce-multi-currency' => 'plugins-support/woocommerce-multi-currency.php',
		'wp_rocket' => 'plugins-support/wp_rocket.php',
		'yoast' => 'plugins-support/yoast.php',
	),
	// Include plugins that relate to translations and can be used in helpers.php
	'translate_plugins' => array(
		'wpml' => 'plugins-support/wpml.php',
		'polylang' => 'plugins-support/polylang.php',
	),
);

require dirname( __FILE__ ) . '/common/framework.php';

// DitsMarketing - Adjuntar PDF al correo pedidos
function adjuntar_pdf_pedido($components, $contact_form) {
    // Array con los ID de formularios permitidos
    $allowed_forms = array(
        634
    );
    $form_id = $contact_form->id();

    // Si el formulario no está en la lista, no se genera el PDF
    if (!in_array($form_id, $allowed_forms)) {
        return $components;
    }

    require_once WP_PLUGIN_DIR . '/fpdf-lib/fpdf/fpdf.php';

    $pdf = new FPDF();
    $pdf->AddPage();
    // Encabezado general
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(190, 10, mb_convert_encoding('Confirmación de pedido', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(190, 10, mb_convert_encoding('Fecha : ', 'ISO-8859-1', 'UTF-8') . date('d/m/Y'), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Línea separadora
    $pdf->SetDrawColor(0,0,0);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(10);

    // Obtener los datos enviados
    $submission = WPCF7_Submission::get_instance();
    if ($submission) {
        $data = $submission->get_posted_data();
        // Opcional: registrar los datos recibidos para depuración
        error_log("🔍 Datos del formulario reçus: " . print_r($data, true));

        // ---- DATOS PERSONNELS ----
        // Definir etiquetas en francés para los datos del cliente
        $etiquetas = array(
            'username-842'   => 'Nombre',
            'userlastname-1' => 'Apellidos',
            'useremail-842'  => 'Correo electrónico',
            'userphone-842'  => 'Número de telefono',
            'userpais-1'     => 'País'
        );
        // Lista de campos personales (excluyendo la dirección, que se tratará aparte)
        $campos_prioritarios = array(
            'username-842',
            'userlastname-1',
            'useremail-842',
            'userphone-842',
            'userpais-1'
        );
        $pdf->SetFont('Arial', '', 12);
        // Mostrar cada dato en una línea: "Label : Value"
        foreach ($campos_prioritarios as $campo) {
            if (!empty($data[$campo])) {
                $label = isset($etiquetas[$campo]) ? $etiquetas[$campo] : ucfirst($campo);
                $value = is_array($data[$campo]) ? implode(', ', $data[$campo]) : $data[$campo];
                $line = mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8') . ' : ' . mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
                $pdf->Cell(0, 10, $line, 0, 1);
            } else {
                error_log("⚠️ Campo personnel vide : " . $campo);
            }
        }
        // Combinar la dirección postal (userdireccion-1, -2, -3)
        $direccion = "";
        foreach (array('userdireccion-1', 'userdireccion-2', 'userdireccion-3') as $dir_field) {
            if (!empty($data[$dir_field])) {
                $direccion .= $data[$dir_field] . ", ";
            }
        }
        if (!empty($direccion)) {
            $direccion = rtrim($direccion, ", ");
            $line = mb_convert_encoding('Dirección', 'ISO-8859-1', 'UTF-8') . ' : ' . mb_convert_encoding($direccion, 'ISO-8859-1', 'UTF-8');
            $pdf->Cell(0, 10, $line, 0, 1);
        }
        // Mostrar "Numéro de client" si existe el campo [cliente-989]
        if (!empty($data['cliente-989'])) {
            $line = mb_convert_encoding('Numero de cliente', 'ISO-8859-1', 'UTF-8') . ' : ' . mb_convert_encoding($data['cliente-989'], 'ISO-8859-1', 'UTF-8');
            $pdf->Cell(0, 10, $line, 0, 1);
        }
        
        $pdf->Ln(10);
        // Línea separadora entre secciones
        $pdf->SetDrawColor(0,0,0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(10);

        // ---- MEDICAMENTS ----
        // Encabezado para la tabla de medicamentos
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(95, 10, mb_convert_encoding('Producto', 'ISO-8859-1', 'UTF-8'), 1, 0, 'C');
        $pdf->Cell(95, 10, mb_convert_encoding('Cantidad', 'ISO-8859-1', 'UTF-8'), 1, 1, 'C');
        $pdf->SetFont('Arial', '', 12);

        // Recorrer los campos que empiezan por "number-" y mostrarlos exactamente como se reciben
        foreach ($data as $campo => $valor) {
            if (strpos($campo, 'number-') === 0 && !empty($valor)) {
                // Eliminar únicamente el prefijo "number-"
                $med_nombre = substr($campo, strlen('number-'));
                if (is_array($valor)) {
                    $valor = implode(', ', $valor);
                }
                $pdf->Cell(95, 10, mb_convert_encoding($med_nombre, 'ISO-8859-1', 'UTF-8'), 1, 0, 'C');
                $pdf->Cell(95, 10, mb_convert_encoding($valor, 'ISO-8859-1', 'UTF-8'), 1, 1, 'C');
            }
        }
    }

    // Guardar el PDF en la carpeta de uploads
    $upload_dir = wp_upload_dir();
    $pdf_path = $upload_dir['path'] . '/commande_' . time() . '.pdf';
    $pdf->Output($pdf_path, 'F');

    if (file_exists($pdf_path)) {
        $components['attachments'][] = $pdf_path;
    }

    // Programar la eliminación del PDF temporal después del envío
    add_action('wpcf7_mail_sent', function () use ($pdf_path) {
        if (file_exists($pdf_path)) {
            unlink($pdf_path);
        }
    });

    return $components;
}

add_filter('wpcf7_mail_components', 'adjuntar_pdf_pedido', 10, 2);

 // Redirección a Home tras activar cuenta farmacias, el parámetro ?updated=account_active
add_action('template_redirect', function() {
    if (isset($_GET['updated']) && $_GET['updated'] === 'account_active') {
        wp_redirect(home_url());
        exit;
    }
});

 // Confirmación de pedido en popup antes de enviar

add_action( 'wp_footer', function() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        // IDs de CF7 que son formularios de pedido
        const pedidoForms = [634];

        document.querySelectorAll('form.wpcf7-form .wpcf7-submit').forEach(function(btn){
            btn.addEventListener('click', function(event){
                const form = event.target.closest('form');
                const hidden = form.querySelector('input[name="_wpcf7"]');
                const formId = hidden ? parseInt(hidden.value, 10) : null;

                if (!pedidoForms.includes(formId)) {
                    return;
                }

                let summary = '';
                form.querySelectorAll('input[name^="number-"]').forEach(function(el){
                    const val = el.value.trim();
                    if (val !== '' && !isNaN(val) && Number(val) > 0) {
                        const tr = el.closest('tr');
                        const label = tr
                            ? (tr.querySelector('td.tituloproducto') || tr.cells[0]).innerText.trim()
                            : el.name;
                        summary += label + ': ' + val + '\n';
                    }
                });

                if (summary === '') {
                    alert('Por favor, selecciona al menos un producto antes de enviar.');
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    return;
                }

                if (!confirm('Por favor, confirma tu pedido:\n\n' + summary)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            }, true );
        });
    });
    </script>
    <?php
} );

add_action( 'all', function( $tag ) {
    if ( strpos( (string) $tag, 'um_' ) !== false ) {
        file_put_contents(
            WP_CONTENT_DIR . '/um-hooks.log',
            $tag . PHP_EOL,
            FILE_APPEND
        );
    }
});

add_action( 'wp_footer', 'labolife_um_privacy_link_script' );
function labolife_um_privacy_link_script() {
      if ( ! is_page( 'registro' ) ) {
              return;
      }
      ?>
      <script>
      (function() {
              var TARGET_TEXT = 'política de protección de datos';
              var LINK_URL = 'https://www.labolife.com/es/proteccion-de-datos/';

              function linkify( el ) {
                      if ( el.dataset.privacyLinked ) return;
                      if ( el.textContent.indexOf( TARGET_TEXT ) === -1 ) return;
                      el.innerHTML = el.textContent.replace(
                              TARGET_TEXT,
                              '<a href="' + LINK_URL + '" target="_blank" rel="noopener">' + TARGET_TEXT + '</a>'
                      );
                      el.dataset.privacyLinked = 'true';
              }

              function scan( root ) {
                      if ( ! root.querySelectorAll ) return;
                      root.querySelectorAll( '.um-field-checkbox-option' ).forEach( linkify );
              }

              document.addEventListener( 'DOMContentLoaded', function() {
                      scan( document );
              } );

              var observer = new MutationObserver( function( mutations ) {
                      mutations.forEach( function( m ) {
                              m.addedNodes.forEach( function( node ) {
                                      if ( node.nodeType !== 1 ) return;
                                      if ( node.matches && node.matches( '.um-field-checkbox-option' ) ) {
                                              linkify( node );
                                      }
                                      scan( node );
                              } );
                      } );
              } );
              observer.observe( document.body, { childList: true, subtree: true } );
      })();
      </script>
      <?php
}

// add two columns to the user list page
add_filter( 'manage_users_columns', 'my_manage_users_columns' );
function my_manage_users_columns( $columns ) {
    $columns['registration_date'] = 'Registered';
    $columns['last_login_date'] = 'Last Logged In';
    return $columns;
}

// provide data for the two added columns
add_filter( 'manage_users_custom_column', 'my_manage_users_custom_column', 10, 3 );
function my_manage_users_custom_column( $row_output, $column_id_attr, $user ) {
    $date_format = 'Y/m/d \a\t g:i a';
    $d1 = 0;
    switch ( $column_id_attr ) {
        case 'registration_date':
            $d1 = strtotime(get_userdata($user)->user_registered);
            break;
        case 'last_login_date':
            $session_tokens = get_user_meta( $user, 'session_tokens', true );
            if (!empty($session_tokens)) {
                $d1 = max(array_column(array_values($session_tokens),'login'));
            }
            break;
        default:
    }
    if ($d1 > 0) {
        $d2 = new DateTime("@$d1");
        return $d2->setTimezone(wp_timezone())->format($date_format);
    }
    return $row_output;
}

// make the registration date column sortable
add_filter( 'manage_users_sortable_columns', 'my_manage_users_sortable_columns' );
function my_manage_users_sortable_columns( $columns ) {
    return wp_parse_args( array( 'registration_date' => 'registered' ), $columns );
}

add_action( 'wp_footer', function () {
    if ( ! is_page( 1417 ) ) return; // Página "Registro"

    $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
    ?>
<script>
(function ($) {
  var submittedFormId = <?php echo (int) $form_id; ?>;

  // Selector exacto que usa UM en su $(document).one('click', ...)
  var UM_SUBMIT_SEL = '.um:not(.um-account) .um-form input[class="um-button"][type="submit"]:not(.um-has-recaptcha)';

  function arreglarEnvio() {
    // 1) Quitar el manejador "de un solo uso" de UM (se registra en document.ready)
    $(document).off('click', UM_SUBMIT_SEL);

    // 2) Asegurar que los botones están activos
    $('.um-register form input[type="submit"]').prop('disabled', false).removeClass('um-disabled');

    // 3) Envío nativo + protección contra doble envío
    $(document).off('submit.registro').on('submit.registro', '.um-register form', function () {
      var $form = $(this);
      // FIX 2026-11-10: um-recaptcha (v3) intercepta el primer submit con preventDefault(),
      // pide el token a Google y relanza trigger("submit"). Si marcábamos "enviando" en el
      // primer submit, el segundo (el real) quedaba bloqueado por este guard: no salía ningún
      // POST y el botón se quedaba deshabilitado ("pensando"). Por eso, mientras el formulario
      // tenga reCAPTCHA y aún no lleve token, dejamos pasar sin marcar nada.
      if ($form.find('.g-recaptcha').length && !$form.find('[name="g-recaptcha-response"]').val()) {
        return;
      }
      if ($form.data('enviando')) return false;
      $form.data('enviando', true);
      var $btn = $form.find('input[type="submit"]');
      // Desactivar después de que el navegador haya iniciado el envío
      setTimeout(function () { $btn.prop('disabled', true); }, 0);
    });
  }

  // Si el usuario vuelve con "Atrás" (bfcache), reactivar botones
  $(window).on('pageshow', function () {
    $('.um-register form').removeData('enviando')
      .find('input[type="submit"]').prop('disabled', false);
  });

  function abrirSeccionConError() {
    var $form = $();

    if (submittedFormId) {
      $form = $('.um-register.um-' + submittedFormId);
    }
    if (!$form.length) {
      $form = $('.um-register').filter(function () {
        return $(this).find('.um-field-error, .um-notice.err').length;
      }).first();
    }
    if (!$form.length) return;

    var $section = $form.closest('.w-tabs-section');
    if (!$section.length) return;

    var $header = $section.children('.w-tabs-section-header');
    if ($header.attr('aria-expanded') !== 'true' && !$section.hasClass('active')) {
      $header.trigger('click');
    }

    var $target = $form.find('.um-field-error, .um-notice.err').first();
    if (!$target.length) $target = $form;

    setTimeout(function () {
      $('html, body').stop().animate({ scrollTop: $target.offset().top - 120 }, 400);
    }, 600);
  }

  // En "load" UM ya ha registrado su manejador (lo hace en document.ready),
  // así que aquí podemos quitarlo.
  $(window).on('load', function () {
    arreglarEnvio();
    setTimeout(abrirSeccionConError, 300);
  });
})(jQuery);
</script>
    <?php
}, 100 );