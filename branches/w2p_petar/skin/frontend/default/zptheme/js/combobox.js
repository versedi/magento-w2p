(function($){
    $.widget("ui.combobox", {
        _create: function(){
            var self = this, 
                select = this.element.hide(), 
                selected = select.children(":selected"), 
                value = selected.val() ? selected.text() : "";
            var input = $("<input>")
                .insertAfter(select)
                .val(value)
                .autocomplete({
                    delay: 0,
                    minLength: 0,
                    source: function(request, response){
                        var matcher = new RegExp($.ui.autocomplete.escapeRegex(request.term), "i");
                        response(select.children("option").map(function(){
                            var $opt = $(this);
                            var text = $opt.text();
                            var value = this.value ? this.value : $.trim(text);
                            if (value && (!request.term || matcher.test(text))) 
                                return {
                                    label: text.replace(new RegExp("(?![^&;]+;)(?!<[^<>]*)(" +
                                    $.ui.autocomplete.escapeRegex(request.term) +
                                    ")(?![^<>]*>)(?![^&;]+;)", "gi"), "<strong>$1</strong>"),
                                    value: text,
                                    option: this
                                };
                        }));
                    },
                    select: function(event, ui){
                        ui.item.option.selected = true;
                        self._trigger("selected", event, {
                            item: ui.item.option
                        });
                    },
                    change: function(event, ui){
                        if (!ui.item) {
                            var matcher = new RegExp("^" + $.ui.autocomplete.escapeRegex($(this).val()) + "$", "i"), valid = false;
                            select.children("option").each(function(){
                                if ($(this).text().match(matcher)) {
                                    this.selected = valid = true;
                                    return false;
                                }
                            });
                            if (!valid) {
                                // remove invalid value, as it didn't match anything
                                // above line is from original implementation, 
                                // we however want to add the value to the list instead
                                // and select it. 
                                var val = $(this).val();
                                select.append('<option>' + val + '</option>');
                                select.val(val);
                                input.data("autocomplete").term = val;
                            }
                        }
                    }
            }).addClass("ui-widget ui-widget-content ui-corner-left");
            
            this.input = input;
            
            input.data("autocomplete")._renderItem = function(ul, item){
                return $("<li></li>").data("item.autocomplete", item).append("<a>" + item.label + "</a>").appendTo(ul);
            };
            
            // repeating the check from above because when clicking on a submit button
            // right after entering new value change event does not fire
            input.blur(function(event){
                var self = jQuery(this);
                var val = self.val();
                
                var matcher = new RegExp("^" + $.ui.autocomplete.escapeRegex(val) + "$", "i"), valid = false;
                select.children("option").each(function(){
                    if ($(this).text().match(matcher)) {
                        this.selected = valid = true;
                        return false;
                    }
                });
                if (!valid) {
                    // remove invalid value, as it didn't match anything
                    // above line is from original implementation, 
                    // we however want to add the value to the list instead
                    // and select it. 
                    select.append('<option>' + val + '</option>');
                    select.val(val);
                    input.data("autocomplete").term = val;
                }
            });
            
            this.button = $("<button type='button'>&nbsp;</button>")
                          .attr("tabIndex", -1)
                          .attr("title", "Show All Items")
                          .insertAfter(input)
                          .button({
                              icons: {
                                  primary: "ui-icon-triangle-1-s"
                              },
                              text: false
                          })
                          .removeClass("ui-corner-all")
                          .addClass("ui-corner-right ui-button-icon")
                          .click(function(){
                              // close if already visible
                              if (input.autocomplete("widget").is(":visible")) {
                                  input.autocomplete("close");
                                  return;
                              }
                              
                              // pass empty string as value to search for, displaying all results
                              input.autocomplete("search", "");
                              input.focus();
                          });
            },
        
        destroy: function(){
            this.input.remove();
            this.button.remove();
            this.element.show();
            $.Widget.prototype.destroy.call(this);
        }
    });
})(jQuery);

jQuery(function(){
    var $selects = jQuery('select');
    $selects.each(function(){
        var $self = jQuery(this);
        var isCombo = false;
        var $children = $self.children('option');
        $children.each(function(){
            var $opt = jQuery(this);
            if (jQuery.trim($opt.text()) == '-') {
                isCombo = true;
                $opt.remove();
            }
        });
        if (isCombo) {
            $self.wrap('<div class="ui-widget"/>').combobox();
        }
    });
});
