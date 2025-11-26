(function($){
    'use strict';

    // Helpers
    function makeNameFromLabel(label){
        if(!label) return '';
        var n = label.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g,'');
        if(!/^[a-z]/.test(n)) n = 'f_' + n;
        return n;
    }

    function nameExistsInList(name, list) {
        var exists = false;
        list.each(function(){
            var val = $(this).find('.codobuf-field-name').val();
            if (val === name) exists = true;
        });
        return exists;
    }

    // Build LI template (openArg true -> open the settings panel)
    function buildFieldLi(field, openArg){
        field = field || {};
        openArg = !!openArg;
        var label = field.label || codobufEditor.i18n.untitled;
        var name = field.name || '';
        var type = field.type || 'text';
        var required = field.required ? 'checked' : '';
        var hint = field.hint || '';
        var options = (field.options || '').replace(/,/g, '\n');

        // types list must match PHP; no file
        var types = [
            { value: 'text', label: 'Text' },
            { value: 'number', label: 'Number' },
            { value: 'textarea', label: 'Textarea' },
            { value: 'select', label: 'Select' },
            { value: 'radio', label: 'Radio' },
            { value: 'checkbox', label: 'Checkbox' }
        ];

        var $li = $('<li class="codobuf-field-item"></li>');
        var $summary = $('<div class="codobuf-field-summary"></div>');
        $summary.append('<span class="dashicons dashicons-menu"></span>');
        $summary.append('<strong class="codobuf-field-preview"></strong>');
        $summary.append('<small class="codobuf-field-type-label">[' + type + ']</small>');
        $summary.append('<a href="#" class="codobuf-toggle-edit">Edit</a>');
        $summary.append('<a href="#" class="codobuf-remove-field">Remove</a>');
        $li.append($summary);

        var settingsClass = openArg ? 'codobuf-field-settings open' : 'codobuf-field-settings collapsed';
        var $settings = $('<div class="' + settingsClass + '"></div>');
        var $table = $('<table class="form-table codobuf-field-table"></table>');

        var $row1 = $('<tr></tr>');
        $row1.append('<td class="codobuf-col-label"><label>Label</label><br><input type="text" class="codobuf-field-label regular-text" value="' + $('<div/>').text(label).html() + '"></td>');
        $row1.append('<td class="codobuf-col-name"><label>Name (unique)</label><br><input type="text" class="codobuf-field-name regular-text" value="' + $('<div/>').text(name).html() + '"><p class="description">Only lowercase letters, numbers and underscores. Auto-generated from label.</p></td>');
        $table.append($row1);

        var $row2 = $('<tr></tr>');
        var $typeSelect = $('<select class="codobuf-field-type"></select>');
        types.forEach(function(t){
            $typeSelect.append('<option value="' + t.value + '"' + (t.value === type ? ' selected' : '') + '>' + t.label + '</option>');
        });
        $row2.append($('<td></td>').append('<label>Type</label><br>').append($typeSelect));
        $row2.append($('<td></td>').append('<label>Required</label><br><label><input type="checkbox" class="codobuf-field-required" '+ required +'> Yes</label>'));
        $table.append($row2);

        var $optionsRow = $('<tr class="codobuf-options-row"><td colspan="2"><label>Options (comma or newline separated)</label><br><textarea class="codobuf-field-options" rows="3"></textarea></td></tr>');
        $optionsRow.find('textarea').val(options);
        if ( ['select','radio'].indexOf(type) === -1 ) {
            $optionsRow.hide();
        }
        $table.append($optionsRow);

        var $rowHint = $('<tr><td colspan="2"><label>Hint / placeholder</label><br><input type="text" class="codobuf-field-hint regular-text" value=""></td></tr>');
        $rowHint.find('input').val(hint);
        $table.append($rowHint);

        $settings.append($table);
        $li.append($settings);

        // populate preview text
        $li.find('.codobuf-field-preview').text(label);
        $li.find('.codobuf-field-type-label').text('[' + type + ']');

        return $li;
    }

    // Update hidden input (target can be specified on wrapper via data-target-input or default option)
    function updateHidden($wrapper) {
        $wrapper = $wrapper || $('#codobuf-fields-editor');
        var targetName = $wrapper.data('target-input') || $('#codobuf-fields-editor').data('target-input') || '';
        var $list = $wrapper.find('.codobuf-fields-list');
        var arr = [];
        $list.find('.codobuf-field-item').each(function(){
            var $li = $(this);
            var label = $li.find('.codobuf-field-label').val() || '';
            var name  = $li.find('.codobuf-field-name').val() || '';
            var type  = $li.find('.codobuf-field-type').val() || 'text';
            var required = $li.find('.codobuf-field-required').is(':checked') ? 1 : 0;
            var hint = $li.find('.codobuf-field-hint').val() || '';
            var options = '';
            if ($li.find('.codobuf-field-options').length) {
                var raw = $li.find('.codobuf-field-options').val() || '';
                options = raw.replace(/\r\n/g, '\n').split(/[\n,]+/).map(function(s){ return s.trim(); }).filter(Boolean).join(',');
            }
            arr.push({
                label: label,
                name: name,
                type: type,
                required: required,
                hint: hint,
                options: options
            });
        });

        var json = JSON.stringify(arr);

        if ( targetName ) {
            // target input might be an option name or hidden field ID
            var $target = $('[name="'+targetName+'"], #'+targetName);
            if ($target.length) {
                $target.val(json);
            } else {
                // fallback to global hidden input
                $('#codobuf-fields-json').val(json);
            }
        } else {
            $('#codobuf-fields-json').val(json);
        }
    }

    // Document ready
    $(function(){
        // Initialize every editor wrapper on the page
        $('.codobuf-editor').each(function(){
            var $wrapper = $(this);
            var $list = $wrapper.find('.codobuf-fields-list');

            // make sortable
            $list.sortable({
                axis: 'y',
                handle: '.dashicons-menu',
                update: function(){ updateHidden($wrapper); }
            });

            // Remove
            $wrapper.on('click', '.codobuf-remove-field', function(e){
                e.preventDefault();
                if (!confirm(codobufEditor.i18n.remove_confirm)) return;
                $(this).closest('.codobuf-field-item').remove();
                updateHidden($wrapper);
            });

            // Toggle edit (open/close)
            $wrapper.on('click', '.codobuf-toggle-edit', function(e){
                e.preventDefault();
                var $li = $(this).closest('.codobuf-field-item');
                $li.find('.codobuf-field-settings').toggleClass('open').toggleClass('collapsed');
            });

            // Add new - new item opens immediately
            $wrapper.on('click', '#codobuf-add-field, #codobuf-calendar-add-field', function(e){
                e.preventDefault();
                var newField = { label: codobufEditor.i18n.untitled, name: '', type: 'text', required: 0, hint: '', options: '' };
                var $li = buildFieldLi(newField, true);
                $list.append($li);
                updateHidden($wrapper);
            });

            // Track manual edits to name field to stop auto generation
            $wrapper.on('input', '.codobuf-field-name', function(){
                $(this).data('touched', true);
                updateHidden($wrapper);
            });

            // Label change -> auto name if name not touched
            $wrapper.on('input', '.codobuf-field-label', function(){
                var $li = $(this).closest('.codobuf-field-item');
                var label = $(this).val();
                $li.find('.codobuf-field-preview').text(label || codobufEditor.i18n.untitled);

                var $name = $li.find('.codobuf-field-name');
                if (!$name.data('touched')) {
                    var candidate = makeNameFromLabel(label);
                    // ensure candidate unique within list
                    var unique = candidate;
                    var counter = 1;
                    while ( nameExistsInList(unique, $li.closest('.codobuf-fields-list').find('.codobuf-field-item')) ) {
                        unique = candidate + '_' + counter;
                        counter++;
                    }
                    $name.val(unique);
                }
                updateHidden($wrapper);
            });

            // Generic change handler to show/hide options row depending on type
            $wrapper.on('change input', '.codobuf-field-type, .codobuf-field-options, .codobuf-field-required, .codobuf-field-hint', function(){
                var $li = $(this).closest('.codobuf-field-item');
                var type = $li.find('.codobuf-field-type').val();
                if ( ['select','radio'].indexOf(type) !== -1 ) {
                    $li.find('.codobuf-options-row').show();
                } else {
                    $li.find('.codobuf-options-row').hide();
                }
                // update preview type label
                $li.find('.codobuf-field-type-label').text('[' + type + ']');
                updateHidden($wrapper);
            });

            // Initialize existing items: collapse settings and set options visibility
            $list.find('.codobuf-field-item').each(function(){
                var $li = $(this);
                // keep settings collapsed unless it already has class open
                $li.find('.codobuf-field-settings').addClass('collapsed').removeClass('open');

                var type = $li.find('.codobuf-field-type').val() || 'text';
                if ( ['select','radio'].indexOf(type) !== -1 ) {
                    $li.find('.codobuf-options-row').show();
                } else {
                    $li.find('.codobuf-options-row').hide();
                }
            });

            // If there is an initial hidden input value and wrapper target is option name, try to populate from it
            // (No destructive operations here; server-side value remains source of truth.)
            updateHidden($wrapper);
        }); // each editor wrapper
    });
})(jQuery);
