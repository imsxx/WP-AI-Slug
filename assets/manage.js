// UTF-8
jQuery(function($){
  var cfg = window.BAISlugManage || {};
  var i18n = cfg.i18n || {};

  function t(key, fallback){ return i18n[key] || fallback; }

  function showNotice(message, type){
    var $box = $('#bai-manage-notice');
    if(!$box.length){
      $box = $('<div id="bai-manage-notice"/>').insertBefore($('.bai-slug-manage h1').first());
    }
    var cls = type === 'error' ? 'notice notice-error' : 'notice notice-success';
    $box.attr('class', cls).html('<p>'+String(message || '')+'</p>').show();
    clearTimeout($box.data('timer'));
    $box.data('timer', setTimeout(function(){ $box.fadeOut(200); }, 3000));
  }

  function toggleRow($tr, editing){
    $tr.find('.text').toggle(!editing);
    $tr.find('.edit-title, .edit-slug, .edit-attr').toggle(!!editing);
    $tr.find('.bai-edit').text(editing ? t('done','Done') : t('edit','Edit'));
  }

  $('table').on('click', '.bai-edit', function(){
    var $btn = $(this), $tr = $btn.closest('tr');
    var editing = $btn.text() === t('done','Done');
    if(!editing){ toggleRow($tr, true); return; }
    var id    = $tr.data('id');
    var title = $tr.find('.edit-title').val();
    var slug  = $tr.find('.edit-slug').val();
    var attr  = $tr.find('.edit-attr').val();
    $btn.prop('disabled', true).text(t('saving','Saving...'));
    $.post(cfg.ajax_url, { action:'bai_update_slug', nonce: cfg.nonce, post_id:id, title:title, slug:slug, attr:attr })
      .done(function(res){
        if(!res || !res.success){
          showNotice((res && res.data && res.data.message) || t('save_failed','Save failed'), 'error');
          return;
        }
        var finalSlug = res.data.slug || slug;
        $tr.find('.col-title .text').text(title);
        $tr.find('.col-slug .text, .col-slug code').text(finalSlug);
        $tr.find('.col-attr .text').text(attr);
        toggleRow($tr, false);
      })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); })
      .always(function(){ $btn.prop('disabled', false).text(t('edit','Edit')); });
  });

  var progressTimer = null;
  function ensureProgress(){
    var $wrap = $('#bai-manage-progress');
    if(!$wrap.length){
      $wrap = $('<span id="bai-manage-progress" class="bai-manage-progress" style="display:none;"><span class="spinner"></span><span class="text"></span></span>');
      $('.bai-manage-toolbar').first().append($wrap);
    }
    return $wrap;
  }
  function setProgress(text, status){
    var $wrap = ensureProgress();
    var $spin = $wrap.find('.spinner');
    $wrap.removeClass('is-error is-success');
    if(status === 'busy'){
      $spin.addClass('is-active');
    } else {
      $spin.removeClass('is-active');
      if(status === 'error'){ $wrap.addClass('is-error'); }
      if(status === 'success'){ $wrap.addClass('is-success'); }
    }
    $wrap.find('.text').text(text || '');
    $wrap.show();
    if(progressTimer){ clearTimeout(progressTimer); }
    progressTimer = null;
  }
  function clearProgress(delay){
    var $wrap = ensureProgress();
    var wait = typeof delay === 'number' ? delay : 2000;
    if(progressTimer){ clearTimeout(progressTimer); }
    progressTimer = setTimeout(function(){ $wrap.fadeOut(200); }, wait);
  }

  // Batch toolbar (queue integration)
  var batchTimer = null;
  var allowAutoRestart = true;
  var wasRunning = false;
  var overlayAccum = 0; // 已处理总数（跨轮累计）
  function setBatchRunning(running){
    var $genBtns = $('.bai-gen, #bai-generate-selected');
    if(running){ $genBtns.prop('disabled', true); }
    else { $genBtns.prop('disabled', false); }
  }
  // Processing state: disable panel controls instead of overlay
  var $manageWrap = $('.bai-slug-manage');
  function setProcessing(run){ $manageWrap.toggleClass('is-processing', !!run); }
  function updateBatchProgress(data){
    if(!data){ return; }
    $('#bai-batch-processed').text(data.processed||0);
    $('#bai-batch-total').text(data.total||0);
    // Pending = global proposals count (server-side)
    if(typeof data.pending === 'number'){
      $('#bai-batch-pending').text(data.pending);
    }
    setBatchRunning(!!data.running);
  }
  function pollBatch(){
    if(batchTimer){ clearTimeout(batchTimer); batchTimer=null; }
    var pts = selectedPostTypes();
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_progress', nonce: BAISlugManage.queue_nonce })
      .done(function(res){
        if(res && res.success){
          var payload = res.data||{};
          if(payload.running){ wasRunning = true; }
          else if(wasRunning){ overlayAccum += (parseInt(payload.processed||0,10) || 0); wasRunning = false; }
          // Query global pending proposals
          $.post(BAISlugManage.ajax_url, { action:'bai_slug_count_proposals', nonce: BAISlugManage.queue_nonce, post_types: pts })
            .done(function(r2){ if(r2 && r2.success){ payload.pending = r2.data && typeof r2.data.count==='number' ? r2.data.count : 0; } updateBatchProgress(payload); });
          // Query pending-slug count and update cached numbers（可用于顶部提示或扩展）
          $.post(BAISlugManage.ajax_url, { action:'bai_slug_count_pending', nonce: BAISlugManage.queue_nonce, post_types: pts })
            .done(function(r3){ /* keep value for possible UI */ });
          fetchProposalsForVisible();
          if(payload && payload.running){ batchTimer=setTimeout(pollBatch, 2000);} 
          // Auto restart if finished but still have pending-slug
          if(payload && !payload.running && allowAutoRestart){
            $.post(BAISlugManage.ajax_url, { action:'bai_slug_count_pending', nonce: BAISlugManage.queue_nonce, post_types: pts }).done(function(rc){ var left=(rc&&rc.success&&rc.data&&rc.data.count)||0; if(left>0){ startQueue(); } else { setProcessing(false); } });
          }
        }
      })
      .fail(function(){ /* silent */ });
  }
  function startQueue(){
    var pts = selectedPostTypes();
    var skip = $('#bai-batch-skip-ai').is(':checked') ? 1 : 0;
    var skipUser = $('#bai-batch-skip-user').is(':checked') ? 1 : 0;
    var batch = parseInt($('#bai-batch-size').val()||'5',10);
    allowAutoRestart = true; setProcessing(true);
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_start', nonce: BAISlugManage.queue_nonce, post_types: pts, batch_size: batch, skip_ai: skip, skip_user: skipUser, pending_only: 1 })
      .done(function(res){ if(res && res.success){ updateBatchProgress(res.data||{}); pollBatch(); } })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); setProcessing(false); });
  }
  // Pause button keeps可点击，其余按钮被置灰
  $(document).on('click', '#bai-batch-pause', function(){ allowAutoRestart=false; });
  function selectedPostTypes(){ return $('.bai-batch-pt:checked').map(function(){ return this.value; }).get(); }
  // -------- AJAX Posts List (文章类处理) --------
  var $postsTbody = $('#bai-posts-tbody'); var $postsPager = $('#bai-posts-pagination');
  function renderPostsRows(items){ var html=''; if(!items||!items.length){ html='<tr><td colspan="7">暂无数据</td></tr>'; } else { items.forEach(function(it){ html+='<tr data-id="'+it.id+'">'
    +'<td><input type="checkbox" class="bai-select" value="'+it.id+'"/></td>'
    +'<td>'+it.id+'</td>'
    +'<td class="col-title"><span class="text">'+$('<div/>').text(it.title||'').html()+'</span><input class="edit-title" type="text" value="'+$('<div/>').text(it.title||'').html()+'" style="display:none;width:100%" /></td>'
    +'<td class="col-slug"><code class="text">'+$('<div/>').text(it.slug||'').html()+'</code><input class="edit-slug" type="text" value="'+$('<div/>').text(it.slug||'').html()+'" style="display:none;width:100%" /></td>'
    +'<td class="col-status">'
      +'<span class="dashicons dashicons-update status-spinner" style="display:none"></span>'
      + (it.proposed ? ('<span class="status-proposed"><code>'+$('<div/>').text(it.proposed).html()+'</code></span>') : '<span class="status-proposed" style="display:none"></span>')
      +'<div class="status-actions" style="margin-top:6px;">'
        +'<button type="button" class="button bai-gen"'+(it.proposed?' style="display:none"':'')+'>生成</button> '
        +'<button type="button" class="button bai-accept"'+(it.proposed?'':' style="display:none"')+'>应用</button> '
        +'<button type="button" class="button bai-reject"'+(it.proposed?'':' style="display:none"')+'>拒绝</button>'
      +'</div>'
    +'</td>'
    +'<td class="col-attr"><span class="text">'+(it.attr||'-')+'</span>'
      +'<select class="edit-attr" style="display:none;"><option value="ai">AI 生成</option><option value="user-edited">人工修改</option></select>'
    +'</td>'
    +'<td class="col-actions"><button class="button bai-edit">编辑</button></td>'
  +'</tr>'; }); }
    $postsTbody.html(html);
  }
  function renderPostsPager(total, per, paged){ var pages=Math.max(1, Math.ceil(total/per)); var html=''; function add(n){ if(n===paged) html+='<span class="page-numbers current">'+n+'</span>'; else html+='<a href="javascript:;" class="page-numbers" data-page="'+n+'">'+n+'</a>'; } if(pages<=12){ for(var i=1;i<=pages;i++){ add(i); } } else { var head=[1,2,3,4,5], tail=[pages-4,pages-3,pages-2,pages-1,pages]; var set=new Set(); head.forEach(function(n){if(n>=1&&n<=pages) set.add(n)}); for(var d=-1; d<=1; d++){ var n=paged+d; if(n>=1&&n<=pages) set.add(n); } tail.forEach(function(n){if(n>=1&&n<=pages) set.add(n)}); var arr=Array.from(set).sort(function(a,b){return a-b}); var prev=0; arr.forEach(function(n){ if(prev && n-prev>1){ html+='<span class="page-numbers dots">…</span>'; } add(n); prev=n; }); } $postsPager.html(html); }
  function fetchPosts(p){ if(!$postsTbody.length) return; p=p||1; $postsTbody.html('<tr><td colspan="7">加载中…</td></tr>'); var ptype=$('#bai-posts-ptype').val()||'post'; var attr=$('#bai-posts-attr').val()||''; var s=$('#bai-posts-search').val()||''; var per=parseInt($('#bai-posts-per').val()||'20',10); $.post(BAISlugManage.ajax_url, { action:'bai_posts_list', nonce: BAISlugManage.nonce, ptype: ptype, attr: attr, s: s, paged: p, per_page: per })
    .done(function(res){ if(res && res.success){ var d=res.data||{}; renderPostsRows(d.items||[]); renderPostsPager(parseInt(d.total||0,10), parseInt(d.per_page||20,10), parseInt(d.paged||1,10)); } else { $postsTbody.html('<tr><td colspan="7">加载失败</td></tr>'); } })
    .fail(function(){ $postsTbody.html('<tr><td colspan="7">网络错误</td></tr>'); }); }
  $('#bai-posts-refresh, #bai-posts-ptype, #bai-posts-attr').on('click change', function(){ fetchPosts(1); });
  $(document).on('keypress', '#bai-posts-search', function(e){ if(e.which===13){ e.preventDefault(); fetchPosts(1); } });
  $postsPager.on('click', 'a.page-numbers', function(){ var p=parseInt($(this).data('page')||'1',10); fetchPosts(p); });
  if($postsTbody.length){ fetchPosts(1); }
  function fetchProposalsForVisible(){
    var ids = $('input.bai-select').map(function(){ return parseInt(this.value,10)||0; }).get();
    if(!ids.length){ return; }
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_get_proposals', nonce: BAISlugManage.nonce, ids: ids })
      .done(function(res){
        if(!res || !res.success || !res.data){ return; }
        var map = res.data||{};
        Object.keys(map).forEach(function(id){ var slug = map[id]; if(!slug){ return; } var $tr=$('tr[data-id="'+id+'"]').first(); var $cell=$tr.find('.col-status'); if(!$cell.find('.status-proposed').length){ $cell.append('<span class="status-proposed"><code>'+slug+'</code></span>'); $cell.find('.bai-gen').hide(); } });
        $('#bai-batch-pending').text($('.status-proposed').length);
      });
  }
  $(document).on('click', '#bai-batch-start', function(){ startQueue(); });
  $(document).on('click', '#bai-batch-pause', function(){
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_pause', nonce: BAISlugManage.queue_nonce })
      .done(function(res){ if(res && res.success){ updateBatchProgress(res.data||{}); } })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); });
  });
  $(document).on('click', '#bai-batch-reset', function(){
    $.post(BAISlugManage.ajax_url, { action:'bai_slug_queue_reset', nonce: BAISlugManage.queue_nonce })
      .done(function(res){ if(res && res.success){ updateBatchProgress(res.data||{}); } })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); });
  });

  $(document).on('change', '#bai-select-all, #bai-select-all-top', function(){
    var checked = $(this).is(':checked');
    $('input.bai-select').prop('checked', checked);
  });

  function collectSelected(){
    var ids = [];
    $('input.bai-select:checked').each(function(){ ids.push(parseInt($(this).val(), 10)); });
    return ids;
  }

  $('#bai-apply-selected').on('click', function(e){
    e.preventDefault();
    var ids = collectSelected();
    if(!ids.length){ showNotice(t('no_selection','No selection'), 'error'); return; }
    var total = ids.length;
    var $btn = $(this).prop('disabled', true);
    var ok = 0, fail = 0, processed = 0;
    var chunkSize = 5;
    setProgress(t('applying','Applying selection') + ' (0/' + total + ')', 'busy');

    function applyChunk(start){
      var slice = ids.slice(start, start + chunkSize);
      if(!slice.length){
        var summary = t('apply_done','Apply finished') + ': ' + ok + '/' + total + ' · ' + t('failed','Failed') + ': ' + fail;
        showNotice(t('applied','Applied') + ': ' + ok + ', ' + t('failed','Failed') + ': ' + fail, fail ? 'error' : 'success');
        setProgress(summary, fail ? 'error' : 'success');
        clearProgress(fail ? 4000 : 2000);
        $btn.prop('disabled', false);
        return;
      }
      $.post(cfg.ajax_url, { action:'bai_slug_queue_apply', nonce: cfg.nonce, ids: slice })
        .done(function(res){
          if(!res || !res.success){
            var msg = (res && res.data && res.data.message) || t('error_generic','Operation failed');
            showNotice(msg, 'error');
            setProgress(msg, 'error');
            clearProgress(4000);
            $btn.prop('disabled', false);
            return;
          }
          var result = res.data.result || {};
          slice.forEach(function(id){
            var rr = result[id];
            if(rr && rr.ok){
              ok++;
              var $tr = $('tr[data-id="'+id+'"]').first();
              $tr.find('.col-slug .text, .col-slug code').text(rr.slug);
              $tr.find('.col-attr .text').text('ai');
              $tr.find('input.bai-select').prop('checked', false);
              $tr.find('.status-proposed').remove();
            } else {
              fail++;
            }
            processed++;
          });
          setProgress(t('applying','Applying selection') + ' (' + processed + '/' + total + ')', 'busy');
          applyChunk(start + chunkSize);
        })
        .fail(function(){
          var msg = t('network_error','Network error');
          showNotice(msg, 'error');
          setProgress(msg, 'error');
          clearProgress(4000);
          $btn.prop('disabled', false);
        });
    }
    applyChunk(0);
  });

  $('#bai-reject-selected').on('click', function(e){
    e.preventDefault();
    var ids = collectSelected();
    if(!ids.length){ showNotice(t('no_selection','No selection'), 'error'); return; }
    var $btn = $(this).prop('disabled', true);
    $.post(cfg.ajax_url, { action:'bai_slug_queue_reject', nonce: cfg.nonce, ids: ids })
      .done(function(res){
        if(!res || !res.success){
          showNotice((res && res.data && res.data.message) || t('error_generic','Operation failed'), 'error');
          return;
        }
        ids.forEach(function(id){
          var $tr = $('tr[data-id="'+id+'"]').first();
          $tr.find('.status-proposed').remove();
          $tr.find('input.bai-select').prop('checked', false);
        });
        showNotice(t('rejected','Rejected'), 'success');
      })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); })
      .always(function(){ $btn.prop('disabled', false); });
  });

  $('table').on('click', '.bai-gen', function(){
    var $btn=$(this), $tr=$btn.closest('tr');
    var id=$tr.data('id');
    var $cell=$tr.find('.col-status');
    $btn.hide();
    $cell.find('.status-proposed').remove();
    $cell.find('.status-spinner').show();
    $.post(cfg.ajax_url, { action:'bai_slug_generate_one', nonce: cfg.nonce, post_id:id })
      .done(function(res){
        if(!res || !res.success){
          showNotice((res && res.data && res.data.message) || t('error_generic','Operation failed'), 'error');
          return;
        }
        var slug=res.data.slug || '';
        $cell.find('.status-spinner').hide();
        if(slug){
          $cell.append('<span class="status-proposed"><code>'+slug+'</code></span>');
        }
      })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); })
      .always(function(){
        $cell.find('.status-spinner').hide();
        if(!$cell.find('.status-proposed').length){ $btn.show(); }
      });
  });

  $('table').on('click', '.bai-accept', function(){
    var $btn=$(this), $tr=$btn.closest('tr');
    var id=$tr.data('id');
    var original = $btn.data('label') || $btn.text();
    $btn.data('label', original);
    $btn.prop('disabled', true).text(t('applying','Applying selection'));
    $.post(cfg.ajax_url, { action:'bai_slug_queue_apply', nonce: cfg.nonce, ids:[id] })
      .done(function(res){
        if(!res || !res.success){
          showNotice((res && res.data && res.data.message) || t('error_generic','Operation failed'), 'error');
          return;
        }
        var rr=(res.data.result||{})[id];
        if(!rr || !rr.ok){
          showNotice(t('error_generic','Operation failed'), 'error');
          return;
        }
        $tr.find('.col-slug .text, .col-slug code').text(rr.slug);
        $tr.find('.col-attr .text').text('ai');
        var $cell=$tr.find('.col-status');
        $cell.find('.status-spinner').hide();
        $cell.find('.bai-gen').hide();
        // 已应用后不再保留“提案”标记，避免与真实状态混淆
        $cell.find('.status-proposed').remove();
        showNotice(t('applied','Applied'), 'success');
      })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); })
      .always(function(){ $btn.prop('disabled', false).text($btn.data('label')); });
  });

  $('table').on('click', '.bai-reject', function(){
    var $btn=$(this), $tr=$btn.closest('tr');
    var id=$tr.data('id');
    var original = $btn.data('label') || $btn.text();
    $btn.data('label', original);
    $btn.prop('disabled', true).text(t('rejected','Rejected'));
    $.post(cfg.ajax_url, { action:'bai_slug_queue_reject', nonce: cfg.nonce, ids:[id] })
      .done(function(res){
        if(!res || !res.success){
          showNotice((res && res.data && res.data.message) || t('error_generic','Operation failed'), 'error');
          return;
        }
        var $cell=$tr.find('.col-status');
        $cell.find('.status-proposed').remove();
        if(!$cell.find('.bai-gen').length){
          $cell.append('<button class="button bai-gen">'+t('generate','Generate')+'</button>');
        }
        showNotice(t('rejected','Rejected'), 'success');
      })
      .fail(function(){ showNotice(t('network_error','Network error'), 'error'); })
      .always(function(){ $btn.prop('disabled', false).text($btn.data('label')); });
  });

  $('#bai-generate-selected').on('click', function(e){
    e.preventDefault();
    var ids=collectSelected();
    if(!ids.length){ showNotice(t('no_selection','No selection'), 'error'); return; }
    var idx=0;
    var $btn=$(this).prop('disabled', true);
    // Disable other actions during generation to avoid conflicts
    var $allControls = $('.bai-accept, .bai-reject, #bai-apply-selected, #bai-reject-selected, .bai-gen');
    $allControls.prop('disabled', true);
    setProgress((t('generate','Generate')||'Generate') + ' (0/' + ids.length + ')', 'busy');
    function next(){
      if(idx>=ids.length){
        $btn.prop('disabled', false);
        $allControls.prop('disabled', false);
        var doneMsg = t('generate_done','Generation finished for all selected');
        showNotice(doneMsg, 'success');
        clearProgress(2000);
        return;
      }
      var id=ids[idx++];
      var $tr=$('tr[data-id="'+id+'"]').first();
      var $cell=$tr.find('.col-status');
      $cell.find('.bai-gen').hide();
      $cell.find('.status-proposed').remove();
      $cell.find('.status-spinner').show();
      $.post(cfg.ajax_url, { action:'bai_slug_generate_one', nonce: cfg.nonce, post_id:id })
        .done(function(res){
          if(res && res.success && res.data && res.data.slug){
            $cell.append('<span class="status-proposed"><code>'+res.data.slug+'</code></span>');
          }
        })
        .always(function(){
          $cell.find('.status-spinner').hide();
          if(!$cell.find('.status-proposed').length){ $cell.find('.bai-gen').show(); }
          setProgress((t('generate','Generate')||'Generate') + ' (' + idx + '/' + ids.length + ')', 'busy');
          next();
        });
    }
    next();
  });
});
