// UTF-8
jQuery(function($){
  function showNotice(msg, type){
    var $box = $('#bai-manage-notice');
    if(!$box.length){ $box = $('<div id="bai-manage-notice"/>').insertBefore($('.bai-slug-manage h1').first()); }
    var cls = type==='error' ? 'notice notice-error' : 'notice notice-success';
    $box.attr('class', cls).html('<p>'+String(msg||'')+'</p>').show();
    setTimeout(function(){ $box.fadeOut(300); }, 2500);
  }

  function toggleRow($tr, edit){
    $tr.find('.text').toggle(!edit);
    $tr.find('.edit-title,.edit-slug,.edit-attr').toggle(edit);
    $tr.find('.bai-edit').text(edit?BAISlugManage.i18n.done:BAISlugManage.i18n.edit);
  }

  $('table').on('click', '.bai-edit', function(){
    var $btn=$(this), $tr=$btn.closest('tr');
    var editing=$btn.text()===BAISlugManage.i18n.done;
    if(!editing){ toggleRow($tr, true); return; }
    var id=$tr.data('id');
    var title=$tr.find('.edit-title').val();
    var slug=$tr.find('.edit-slug').val();
    var attr=$tr.find('.edit-attr').val();
    $btn.prop('disabled', true).text(BAISlugManage.i18n.saving);
    $.post(BAISlugManage.ajax_url, { action:'bai_update_slug', nonce: BAISlugManage.nonce, post_id:id, title:title, slug:slug, attr:attr })
      .done(function(res){
        if(!res||!res.success){ showNotice((res&&res.data&&res.data.message)||BAISlugManage.i18n.save_failed, 'error'); return; }
        var finalSlug=res.data.slug||slug;
        $tr.find('.col-title .text').text(title);
        $tr.find('.col-slug .text').text(finalSlug);
        $tr.find('.col-attr .text').text(attr);
        toggleRow($tr, false);
      })
      .fail(function(){ showNotice(BAISlugManage.i18n.network_error, 'error'); })
      .always(function(){ $btn.prop('disabled', false).text(BAISlugManage.i18n.edit); });
  });

  $(document).on('change', '#bai-select-all, #bai-select-all-top', function(){
    var on=$(this).is(':checked'); $('input.bai-select').prop('checked', on);
  });

  function collectSelected(){ var ids=[]; $('input.bai-select:checked').each(function(){ ids.push(parseInt($(this).val(),10)); }); return ids; }

  $('#bai-apply-selected').on('click', function(e){
    e.preventDefault();
    var ids=collectSelected(); if(!ids.length){ showNotice(BAISlugManage.i18n.no_selection || '未选择任何项', 'error'); return; }
    var $btn=$(this).prop('disabled', true);
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_apply', nonce: BAISlugManage.nonce, ids: ids })
      .done(function(res){
        if(!res||!res.success){ showNotice((res&&res.data&&res.data.message)||'操作失败', 'error'); return; }
        var r=res.data.result||{}; var ok=0, fail=0;
        ids.forEach(function(id){ var rr=r[id]; if(rr&&rr.ok){ ok++; var $tr=$('tr[data-id="'+id+'"]').first(); $tr.find('.col-slug .text, .col-slug code').text(rr.slug); $tr.find('.col-attr .text').text('ai'); $tr.find('input.bai-select').prop('checked', false); $tr.find('.status-proposed').remove(); } else { fail++; } });
        showNotice((BAISlugManage.i18n.applied||'已应用')+': '+ok+', 失败: '+fail);
      })
      .fail(function(){ showNotice(BAISlugManage.i18n.network_error || '网络错误', 'error'); })
      .always(function(){ $btn.prop('disabled', false); });
  });

  $('#bai-reject-selected').on('click', function(e){
    e.preventDefault();
    var ids=collectSelected(); if(!ids.length){ showNotice(BAISlugManage.i18n.no_selection || '未选择任何项', 'error'); return; }
    var $btn=$(this).prop('disabled', true);
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_reject', nonce: BAISlugManage.nonce, ids: ids })
      .done(function(res){
        if(!res||!res.success){ showNotice((res&&res.data&&res.data.message)||'操作失败', 'error'); return; }
        ids.forEach(function(id){ var $tr=$('tr[data-id="'+id+'"]').first(); $tr.find('.status-proposed').remove(); $tr.find('input.bai-select').prop('checked', false); });
        showNotice(BAISlugManage.i18n.rejected || '已拒绝');
      })
      .fail(function(){ showNotice(BAISlugManage.i18n.network_error || '网络错误', 'error'); })
      .always(function(){ $btn.prop('disabled', false); });
  });

  $('table').on('click', '.bai-gen', function(){
    var $btn=$(this), $tr=$btn.closest('tr'); var id=$tr.data('id'); var $cell=$tr.find('.col-status');
    $btn.hide(); $cell.find('.status-proposed').remove(); $cell.find('.status-spinner').show();
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_generate_one', nonce: BAISlugManage.nonce, post_id:id })
      .done(function(res){ if(!res||!res.success){ showNotice((res&&res.data&&res.data.message)||'操作失败', 'error'); return; } var slug=res.data.slug||''; $cell.find('.status-spinner').hide(); if(slug){ $cell.append('<span class="status-proposed"><code>'+slug+'</code></span>'); } })
      .fail(function(){ showNotice(BAISlugManage.i18n.network_error || '网络错误', 'error'); })
      .always(function(){ $cell.find('.status-spinner').hide(); if(!$cell.find('.status-proposed').length){ $btn.show(); } });
  });

  $('table').on('click', '.bai-accept', function(){
    var $btn=$(this), $tr=$btn.closest('tr'); var id=$tr.data('id');
    $btn.prop('disabled', true).text('应用中...');
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_apply', nonce: BAISlugManage.nonce, ids:[id] })
      .done(function(res){ if(!res||!res.success){ showNotice((res&&res.data&&res.data.message)||'操作失败', 'error'); return; } var rr=(res.data.result||{})[id]; if(!rr||!rr.ok){ showNotice('无法应用该建议，已失败', 'error'); return; } $tr.find('.col-slug .text, .col-slug code').text(rr.slug); $tr.find('.col-attr .text').text('ai'); var $cell=$tr.find('.col-status'); $cell.find('.status-spinner').hide(); $cell.find('.bai-gen').hide(); $cell.find('.status-proposed').remove(); $cell.append('<span class="status-proposed"><code>'+rr.slug+'</code></span>'); })
      .fail(function(){ showNotice(BAISlugManage.i18n.network_error || '网络错误', 'error'); })
      .always(function(){ $btn.prop('disabled', false).text('接受'); });
  });

  $('table').on('click', '.bai-reject', function(){
    var $btn=$(this), $tr=$btn.closest('tr'); var id=$tr.data('id');
    $btn.prop('disabled', true).text('拒绝中...');
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_reject', nonce: BAISlugManage.nonce, ids:[id] })
      .done(function(res){ if(!res||!res.success){ showNotice((res&&res.data&&res.data.message)||'操作失败', 'error'); return; } var $cell=$tr.find('.col-status'); $cell.find('.status-proposed').remove(); if(!$cell.find('.bai-gen').length){ $cell.append('<button class="button bai-gen">生成</button>'); } })
      .fail(function(){ showNotice(BAISlugManage.i18n.network_error || '网络错误', 'error'); })
      .always(function(){ $btn.prop('disabled', false).text('拒绝'); });
  });

  $('#bai-generate-selected').on('click', function(e){
    e.preventDefault();
    var ids=collectSelected(); if(!ids.length){ showNotice(BAISlugManage.i18n.no_selection || '未选择任何项', 'error'); return; }
    var idx=0; var $btn=$(this).prop('disabled', true);
    function next(){
      if(idx>=ids.length){ $btn.prop('disabled', false); showNotice('已完成所选生成'); return; }
      var id=ids[idx++]; var $tr=$('tr[data-id="'+id+'"]').first(); var $cell=$tr.find('.col-status');
      $cell.find('.bai-gen').hide(); $cell.find('.status-proposed').remove(); $cell.find('.status-spinner').show();
      $.post(BAISlugManage.ajax_url, { action:'bai_slug_generate_one', nonce: BAISlugManage.nonce, post_id:id })
        .done(function(res){ if(res&&res.success&&res.data&&res.data.slug){ $cell.append('<span class="status-proposed"><code>'+res.data.slug+'</code></span>'); } })
        .always(function(){ $cell.find('.status-spinner').hide(); if(!$cell.find('.status-proposed').length){ $cell.find('.bai-gen').show(); } next(); });
    }
    next();
  });
});

