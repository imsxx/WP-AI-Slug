// UTF-8
jQuery(function($){
  var cfg = window.BAISlugPost;
  if(!cfg){ return; }

  function notify(message, type){
    var text = String(message || '');
    if(window.wp && wp.data && wp.data.dispatch){
      var kind = (type === 'error' || type === 'success') ? type : 'info';
      try {
        wp.data.dispatch('core/notices').createNotice(kind, text, { isDismissible: true });
        return;
      } catch (e) { /* noop fallback */ }
    }
    if(type === 'error'){
      console.error(text);
      window.alert(text);
    } else if (type === 'success') {
      console.log(text);
    }
  }

  function regen(postId){
    return $.post(cfg.ajax_url, {
      action: 'bai_slug_regenerate_post',
      nonce: cfg.nonce_regen,
      post_id: postId
    });
  }

  var sourceAi = cfg.i18n && cfg.i18n.source_ai ? cfg.i18n.source_ai : 'AI 生成';
  var sourceUser = cfg.i18n && cfg.i18n.source_user ? cfg.i18n.source_user : '人工修改';

  if(cfg.context === 'single' && cfg.post_id){
    var $buttons = $('#edit-slug-buttons');
    if($buttons.length){
      var $wrap = $('<span class="bai-slug-regen-wrap" style="margin-left:8px;"></span>');
      var label = (cfg.i18n && cfg.i18n.regenerate) ? cfg.i18n.regenerate : '重新生成';
      var successMsg = (cfg.i18n && cfg.i18n.generated) ? cfg.i18n.generated : '已生成';
      var errorMsg = (cfg.i18n && cfg.i18n.error_generic) ? cfg.i18n.error_generic : '操作失败';
      var $btn = $('<button type="button" class="button" />').text(label);
      var $spin = $('<span class="spinner"></span>');
      $wrap.append($btn).append($spin);
      $buttons.append($wrap);
      $btn.on('click', function(){
        if($btn.prop('disabled')){ return; }
        $btn.prop('disabled', true);
        $spin.addClass('is-active');
        regen(cfg.post_id)
          .done(function(res){
            if(!res || !res.success){
              var msg = (res && res.data && res.data.message) || errorMsg;
              notify(msg, 'error');
              return;
            }
            var slug = res.data.slug || '';
            if(slug){
              $('#post_name').val(slug);
              $('#editable-post-name-full').text(slug);
              $('#editable-post-name').text(slug);
              $('#editable-post-name-with-dash').text(slug);
              $('#editable-post-name').trigger('change');
              var $sample = $('#sample-permalink');
              if($sample.length && res.data.permalink){
                $sample.text(res.data.permalink);
                $sample.attr('href', res.data.permalink);
              }
            }
            notify(successMsg, 'success');
          })
          .fail(function(){
            var netErr = (cfg.i18n && cfg.i18n.network_error) ? cfg.i18n.network_error : '网络错误';
            notify(netErr, 'error');
          })
          .always(function(){
            $btn.prop('disabled', false);
            $spin.removeClass('is-active');
          });
      });
    }
  }

  if(cfg.context === 'list'){
    var errorMsg = (cfg.i18n && cfg.i18n.error_generic) ? cfg.i18n.error_generic : '操作失败';
    var netErr = (cfg.i18n && cfg.i18n.network_error) ? cfg.i18n.network_error : '网络错误';
    var savedMsg = (cfg.i18n && cfg.i18n.saved) ? cfg.i18n.saved : '已保存';
    var successMsg = (cfg.i18n && cfg.i18n.generated) ? cfg.i18n.generated : '已生成';

    function getWrap($el){ return $el.closest('.bai-inline-slug'); }
    function setBusy($wrap, busy){
      var $spin = $wrap.find('.spinner').first();
      if(busy){ $spin.addClass('is-active'); }
      else { $spin.removeClass('is-active'); }
    }
    function toggleEdit($wrap, editing){
      $wrap.toggleClass('is-editing', !!editing);
      $wrap.find('.edit-row').toggle(!!editing);
      $wrap.find('.action-row .bai-inline-edit').toggle(!editing);
    }
    function updateSlug($wrap, slug){
      $wrap.find('.slug-display').text(slug);
      $wrap.find('.slug-input').val(slug);
    }
    function updateSource($wrap, label){
      $wrap.find('.source-label').text(label || '—');
    }

    $('.bai-inline-slug').on('click', '.bai-inline-edit', function(){
      toggleEdit(getWrap($(this)), true);
    });

    $('.bai-inline-slug').on('click', '.bai-inline-cancel', function(){
      var $wrap = getWrap($(this));
      toggleEdit($wrap, false);
      var current = $wrap.find('.slug-display').text();
      $wrap.find('.slug-input').val(current);
    });

    $('.bai-inline-slug').on('click', '.bai-inline-save', function(){
      var $wrap = getWrap($(this));
      var postId = parseInt($wrap.data('id'), 10) || 0;
      var slug = $.trim($wrap.find('.slug-input').val());
      if(!postId || !slug){
        notify(errorMsg, 'error');
        return;
      }
      setBusy($wrap, true);
      $.post(cfg.ajax_url, {
        action: 'bai_update_slug',
        nonce: cfg.nonce_update,
        post_id: postId,
        slug: slug,
        attr: 'user-edited'
      }).done(function(res){
        if(!res || !res.success){
          var msg = (res && res.data && res.data.message) || errorMsg;
          notify(msg, 'error');
          return;
        }
        var finalSlug = res.data.slug || slug;
        updateSlug($wrap, finalSlug);
        updateSource($wrap, sourceUser);
        notify(savedMsg, 'success');
        toggleEdit($wrap, false);
      }).fail(function(){
        notify(netErr, 'error');
      }).always(function(){
        setBusy($wrap, false);
      });
    });

    $('.bai-inline-slug').on('click', '.bai-inline-regenerate', function(){
      var $wrap = getWrap($(this));
      var postId = parseInt($wrap.data('id'), 10) || 0;
      if(!postId){
        notify(errorMsg, 'error');
        return;
      }
      setBusy($wrap, true);
      regen(postId).done(function(res){
        if(!res || !res.success){
          var msg = (res && res.data && res.data.message) || errorMsg;
          notify(msg, 'error');
          return;
        }
        var slug = res.data.slug || '';
        if(slug){
          updateSlug($wrap, slug);
          updateSource($wrap, sourceAi);
          notify(successMsg, 'success');
        }
        toggleEdit($wrap, false);
      }).fail(function(){
        notify(netErr, 'error');
      }).always(function(){
        setBusy($wrap, false);
      });
    });
  }
});
