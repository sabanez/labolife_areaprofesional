<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UMFVM_Admin {

    public static function add_menu() {
        add_submenu_page(
            'ultimatemember',
            'Field Value Mapper',
            'Field Value Mapper',
            'manage_options',
            'umfvm-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue( $hook ) {
        if ( false === strpos( $hook, 'umfvm-settings' ) ) {
            return;
        }
        // jQuery ya está disponible en el admin de WP
        wp_enqueue_script( 'jquery' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: devuelve los campos con opciones de un formulario UM
    // ─────────────────────────────────────────────────────────────────────────
    public static function ajax_get_fields() {
        check_ajax_referer( 'umfvm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $form_id = intval( $_POST['form_id'] ?? 0 );
        $raw     = get_post_meta( $form_id, '_um_custom_fields', true );

        if ( ! is_array( $raw ) || empty( $raw ) ) {
            wp_send_json_error( 'No se han encontrado campos en este formulario. Asegúrate de que el formulario tiene campos configurados.' );
        }

        $choice_types = array( 'radio', 'checkbox', 'select', 'multiselect' );
        $fields       = array();

        foreach ( $raw as $fk => $cfg ) {
            if ( ! is_array( $cfg ) ) {
                continue;
            }
            $type = $cfg['type'] ?? '';
            if ( ! in_array( $type, $choice_types, true ) ) {
                continue;
            }
            $options = self::parse_options( $cfg );
            if ( empty( $options ) ) {
                continue;
            }
            $fields[ $fk ] = array(
                'label'   => $cfg['label'] ?? $fk,
                'type'    => $type,
                'options' => $options,
            );
        }

        if ( empty( $fields ) ) {
            wp_send_json_error( 'Este formulario no tiene campos de tipo radio, checkbox o select con opciones definidas.' );
        }

        $all   = get_option( UMFVM_OPTION, array() );
        $saved = $all[ (string) $form_id ] ?? array();

        wp_send_json_success( array(
            'fields' => $fields,
            'saved'  => $saved,
        ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: guarda el mapeo de un campo en un formulario
    // ─────────────────────────────────────────────────────────────────────────
    public static function ajax_save_mapping() {
        check_ajax_referer( 'umfvm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $form_id   = (string) intval( $_POST['form_id'] ?? 0 );
        $field_key = sanitize_key( $_POST['field_key'] ?? '' );
        $map_json  = wp_unslash( $_POST['map'] ?? '{}' );
        $map_raw   = json_decode( $map_json, true );

        if ( ! $form_id || ! $field_key || ! is_array( $map_raw ) ) {
            wp_send_json_error( 'Datos incompletos.' );
        }

        $map = array();
        foreach ( $map_raw as $label => $value ) {
            $label = sanitize_text_field( $label );
            $value = sanitize_text_field( $value );
            if ( $label !== '' && $value !== '' ) {
                $map[ $label ] = $value;
            }
        }

        if ( empty( $map ) ) {
            wp_send_json_error( 'El mapeo está vacío. Introduce al menos un valor mapeado.' );
        }

        $all                           = get_option( UMFVM_OPTION, array() );
        $all[ $form_id ][ $field_key ] = $map;
        update_option( UMFVM_OPTION, $all );

        wp_send_json_success( array( 'message' => 'Mapeo guardado correctamente.' ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: elimina el mapeo de un campo en un formulario
    // ─────────────────────────────────────────────────────────────────────────
    public static function ajax_delete_mapping() {
        check_ajax_referer( 'umfvm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $form_id   = (string) intval( $_POST['form_id'] ?? 0 );
        $field_key = sanitize_key( $_POST['field_key'] ?? '' );

        $all = get_option( UMFVM_OPTION, array() );
        unset( $all[ $form_id ][ $field_key ] );
        if ( empty( $all[ $form_id ] ) ) {
            unset( $all[ $form_id ] );
        }
        update_option( UMFVM_OPTION, $all );

        wp_send_json_success( array( 'message' => 'Mapeo eliminado.' ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extrae las opciones de un campo UM como array plano de labels.
     * UM puede almacenarlas como string con saltos de línea o como array.
     */
    private static function parse_options( $cfg ) {
        $raw = $cfg['options'] ?? array();

        if ( is_string( $raw ) && $raw !== '' ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
            return array_values( $lines );
        }

        if ( is_array( $raw ) ) {
            return array_values( $raw );
        }

        return array();
    }

    private static function get_um_forms() {
        $posts = get_posts( array(
            'post_type'      => 'um_form',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $forms = array();
        foreach ( $posts as $post ) {
            $forms[ $post->ID ] = $post->post_title;
        }
        return $forms;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Página de administración
    // ─────────────────────────────────────────────────────────────────────────
    public static function render_page() {
        $forms   = self::get_um_forms();
        $all     = get_option( UMFVM_OPTION, array() );
        $nonce   = wp_create_nonce( 'umfvm_nonce' );
        $ajaxurl = admin_url( 'admin-ajax.php' );

        $saved_display = array();
        foreach ( $all as $form_id => $fields ) {
            $form_name = get_the_title( $form_id ) ?: "Formulario #{$form_id}";
            foreach ( $fields as $field_key => $map ) {
                $saved_display[] = array(
                    'form_id'   => $form_id,
                    'form_name' => $form_name,
                    'field_key' => $field_key,
                    'map'       => $map,
                );
            }
        }
        ?>
        <div class="wrap">
            <h1>UM Field Value Mapper</h1>
            <p style="color:#666; margin-top:4px">
                Define un valor de envío diferente al label visible para campos radio, checkbox y select de Ultimate Member.
            </p>

            <div id="umfvm-notice" style="display:none" class="notice notice-success is-dismissible"><p></p></div>
            <div id="umfvm-error"  style="display:none" class="notice notice-error  is-dismissible"><p></p></div>

            <?php if ( empty( $forms ) ) : ?>
                <div class="notice notice-warning"><p>No se han encontrado formularios de Ultimate Member.</p></div>
            <?php else : ?>

            <!-- ── Editor ───────────────────────────────────────────────── -->
            <div class="postbox" style="padding:16px 20px; margin-top:20px">
                <h2 class="hndle" style="padding:0 0 12px">Añadir / editar mapeo</h2>

                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="width:160px"><label for="umfvm-form">Formulario</label></th>
                        <td>
                            <select id="umfvm-form" style="min-width:300px">
                                <option value="">— Selecciona un formulario —</option>
                                <?php foreach ( $forms as $id => $name ) : ?>
                                    <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?> (ID: <?php echo esc_html( $id ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="umfvm-field-row" style="display:none">
                        <th><label for="umfvm-field">Campo</label></th>
                        <td>
                            <select id="umfvm-field" style="min-width:300px">
                                <option value="">— Selecciona un campo —</option>
                            </select>
                            <span id="umfvm-loading" style="display:none; margin-left:10px; color:#666">Cargando...</span>
                        </td>
                    </tr>
                </table>

                <div id="umfvm-options-wrap" style="display:none; margin-top:20px">
                    <h3 id="umfvm-options-title" style="margin:0 0 10px; font-size:14px; font-weight:600"></h3>

                    <div style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:10px 14px; margin-bottom:14px; max-width:580px">
                        <label for="umfvm-csv-input" style="display:block; font-weight:600; margin-bottom:6px">
                            Importar valores desde CSV
                        </label>
                        <p style="margin:0 0 8px; color:#666; font-size:12px">
                            Pega los values separados por comas, en el mismo orden en que aparecen los labels de abajo (uno por cada fila).
                        </p>
                        <textarea id="umfvm-csv-input" rows="2" class="large-text code" placeholder="Endocrinology, Pharmacies, true, ..."></textarea>
                        <p style="margin:8px 0 0">
                            <button type="button" id="umfvm-csv-apply" class="button">Aplicar CSV a los campos</button>
                            <span id="umfvm-csv-msg" style="margin-left:10px; color:#666; font-size:12px"></span>
                        </p>
                    </div>

                    <table id="umfvm-options-table" class="widefat" style="max-width:580px; border-collapse:collapse">
                        <thead>
                            <tr style="background:#f0f0f1">
                                <th style="padding:8px 12px; text-align:left">Label visible (UM)</th>
                                <th style="padding:8px 6px; text-align:center; width:30px">→</th>
                                <th style="padding:8px 12px; text-align:left">Value enviado</th>
                            </tr>
                        </thead>
                        <tbody id="umfvm-options-body"></tbody>
                    </table>
                    <p style="margin-top:14px">
                        <button id="umfvm-save" class="button button-primary">Guardar mapeo</button>
                        <span id="umfvm-saving" style="display:none; margin-left:10px; color:#666; font-style:italic">Guardando...</span>
                    </p>
                </div>
            </div>

            <?php endif; ?>

            <!-- ── Mapeos guardados ──────────────────────────────────────── -->
            <?php if ( ! empty( $saved_display ) ) : ?>
            <div class="postbox" style="padding:16px 20px; margin-top:20px" id="umfvm-saved-section">
                <h2 class="hndle" style="padding:0 0 12px">Mapeos configurados</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:200px">Formulario</th>
                            <th style="width:200px">Campo</th>
                            <th>Valores mapeados</th>
                            <th style="width:140px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="umfvm-saved-body">
                    <?php foreach ( $saved_display as $row ) : ?>
                        <tr id="umfvm-row-<?php echo esc_attr( $row['form_id'] . '-' . $row['field_key'] ); ?>">
                            <td><?php echo esc_html( $row['form_name'] ); ?></td>
                            <td><code><?php echo esc_html( $row['field_key'] ); ?></code></td>
                            <td>
                                <?php foreach ( $row['map'] as $label => $val ) : ?>
                                    <span style="display:inline-flex; align-items:center; gap:4px; margin:2px 6px 2px 0; background:#f0f0f1; padding:2px 8px; border-radius:3px; font-size:12px">
                                        <?php echo esc_html( $label ); ?>
                                        <span style="color:#aaa">→</span>
                                        <strong><?php echo esc_html( $val ); ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <button class="button umfvm-edit"
                                    data-form="<?php echo esc_attr( $row['form_id'] ); ?>"
                                    data-field="<?php echo esc_attr( $row['field_key'] ); ?>">
                                    Editar
                                </button>
                                &nbsp;
                                <button class="button umfvm-delete"
                                    data-form="<?php echo esc_attr( $row['form_id'] ); ?>"
                                    data-field="<?php echo esc_attr( $row['field_key'] ); ?>"
                                    style="color:#b32d2e">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            var ajaxurl  = <?php echo wp_json_encode( $ajaxurl ); ?>;
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var allFields = {};  // campos del formulario actualmente seleccionado
            var allSaved  = {};  // mapeos ya guardados del formulario actual

            function showNotice(msg, isError) {
                var $el = isError ? $('#umfvm-error') : $('#umfvm-notice');
                $el.find('p').text(msg).end().show();
                if (!isError) setTimeout(function() { $el.hide(); }, 4000);
            }

            function loadFields(formId, callback) {
                $('#umfvm-loading').show();
                $('#umfvm-field').prop('disabled', true);
                $('#umfvm-options-wrap').hide();

                $.post(ajaxurl, {
                    action:  'umfvm_get_fields',
                    nonce:   nonce,
                    form_id: formId
                }, function(res) {
                    $('#umfvm-loading').hide();
                    $('#umfvm-field').prop('disabled', false);

                    if (!res.success) {
                        showNotice(res.data, true);
                        return;
                    }

                    allFields = res.data.fields;
                    allSaved  = res.data.saved;

                    var $sel = $('#umfvm-field')
                        .empty()
                        .append($('<option>').val('').text('— Selecciona un campo —'));

                    $.each(allFields, function(fk, f) {
                        $sel.append(
                            $('<option>').val(fk).text(f.label + '  (' + fk + ')  [' + f.type + ']')
                        );
                    });

                    $('#umfvm-field-row').show();

                    if (typeof callback === 'function') callback();
                });
            }

            function renderOptions(fk) {
                if (!fk || !allFields[fk]) {
                    $('#umfvm-options-wrap').hide();
                    return;
                }

                var f      = allFields[fk];
                var saved  = allSaved[fk] || {};
                var $tbody = $('#umfvm-options-body').empty();

                $('#umfvm-options-title').text('Opciones del campo: ' + f.label + ' (' + fk + ')');

                $.each(f.options, function(i, label) {
                    var $row   = $('<tr>');
                    var $input = $('<input type="text" class="regular-text umfvm-val">')
                        .attr('placeholder', 'value a enviar')
                        .data('label', label)
                        .val(saved[label] || '');

                    $row.append(
                        $('<td style="padding:6px 12px">').text(label),
                        $('<td style="padding:6px; text-align:center; color:#aaa">').text('→'),
                        $('<td style="padding:6px 12px">').append($input)
                    );
                    $tbody.append($row);
                });

                $('#umfvm-csv-input').val('');
                $('#umfvm-csv-msg').text('');
                $('#umfvm-options-wrap').show();
            }

            // Parsea un CSV simple: separado por comas, admite valores entre
            // comillas dobles para poder incluir comas literales ("Madrid, España").
            function parseCsvLine(str) {
                var out = [];
                var cur = '';
                var inQuotes = false;
                for (var i = 0; i < str.length; i++) {
                    var ch = str[i];
                    if (inQuotes) {
                        if (ch === '"') {
                            if (str[i + 1] === '"') { cur += '"'; i++; }
                            else { inQuotes = false; }
                        } else {
                            cur += ch;
                        }
                    } else {
                        if (ch === '"') inQuotes = true;
                        else if (ch === ',') { out.push(cur); cur = ''; }
                        else cur += ch;
                    }
                }
                out.push(cur);
                return out.map(function(v) { return $.trim(v); });
            }

            // Aplica los valores del CSV a los inputs, en el mismo orden
            // en que se muestran las filas (que es el orden de las opciones del campo).
            $('#umfvm-csv-apply').on('click', function() {
                var raw = $('#umfvm-csv-input').val();
                if ($.trim(raw) === '') {
                    $('#umfvm-csv-msg').css('color', '#b32d2e').text('Pega antes una lista de valores.');
                    return;
                }

                var values = parseCsvLine(raw);
                var $inputs = $('#umfvm-options-body .umfvm-val');

                $inputs.each(function(i) {
                    if (typeof values[i] !== 'undefined' && values[i] !== '') {
                        $(this).val(values[i]);
                    }
                });

                var msg = 'Se han aplicado ' + Math.min(values.length, $inputs.length) + ' de ' + $inputs.length + ' campos.';
                if (values.length !== $inputs.length) {
                    msg += ' ⚠ El número de valores del CSV (' + values.length + ') no coincide con el número de labels (' + $inputs.length + '). Revisa el orden antes de guardar.';
                    $('#umfvm-csv-msg').css('color', '#b32d2e');
                } else {
                    $('#umfvm-csv-msg').css('color', '#00a32a');
                }
                $('#umfvm-csv-msg').text(msg);
            });

            // Formulario seleccionado → carga campos
            $('#umfvm-form').on('change', function() {
                var formId = $(this).val();
                $('#umfvm-field-row, #umfvm-options-wrap').hide();
                if (!formId) return;
                loadFields(formId);
            });

            // Campo seleccionado → muestra opciones
            $('#umfvm-field').on('change', function() {
                renderOptions($(this).val());
            });

            // Guardar mapeo
            $('#umfvm-save').on('click', function() {
                var formId = $('#umfvm-form').val();
                var fk     = $('#umfvm-field').val();

                if (!formId || !fk) {
                    showNotice('Selecciona un formulario y un campo.', true);
                    return;
                }

                var map = {};
                $('#umfvm-options-body .umfvm-val').each(function() {
                    var label = $(this).data('label');
                    var val   = $.trim($(this).val());
                    if (val !== '') map[label] = val;
                });

                if ($.isEmptyObject(map)) {
                    showNotice('Introduce al menos un value mapeado antes de guardar.', true);
                    return;
                }

                $('#umfvm-saving').show();
                $('#umfvm-save').prop('disabled', true);

                $.post(ajaxurl, {
                    action:    'umfvm_save_mapping',
                    nonce:     nonce,
                    form_id:   formId,
                    field_key: fk,
                    map:       JSON.stringify(map)
                }, function(res) {
                    $('#umfvm-saving').hide();
                    $('#umfvm-save').prop('disabled', false);

                    if (res.success) {
                        showNotice('Mapeo guardado correctamente.');
                        setTimeout(function() { location.reload(); }, 1200);
                    } else {
                        showNotice(res.data, true);
                    }
                });
            });

            // Eliminar mapeo
            $(document).on('click', '.umfvm-delete', function() {
                if (!confirm('¿Eliminar este mapeo? Esta acción no se puede deshacer.')) return;

                var $btn     = $(this);
                var formId   = $btn.data('form');
                var fieldKey = $btn.data('field');

                $.post(ajaxurl, {
                    action:    'umfvm_delete_mapping',
                    nonce:     nonce,
                    form_id:   formId,
                    field_key: fieldKey
                }, function(res) {
                    if (res.success) {
                        $('#umfvm-row-' + formId + '-' + fieldKey).fadeOut(300, function() {
                            $(this).remove();
                            if ($('#umfvm-saved-body tr').length === 0) {
                                $('#umfvm-saved-section').hide();
                            }
                        });
                    } else {
                        showNotice(res.data, true);
                    }
                });
            });

            // Editar mapeo: carga el formulario+campo en el editor
            $(document).on('click', '.umfvm-edit', function() {
                var formId   = String($(this).data('form'));
                var fieldKey = String($(this).data('field'));

                $('#umfvm-form').val(formId);
                loadFields(formId, function() {
                    $('#umfvm-field').val(fieldKey);
                    renderOptions(fieldKey);
                });

                $('html, body').animate({ scrollTop: 0 }, 300);
            });
        });
        </script>
        <?php
    }
}
