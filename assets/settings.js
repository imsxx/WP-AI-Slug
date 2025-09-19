// UTF-8
jQuery(function($){
  function api(data){ return $.post(BAISlug.ajax_url, data); }

  // Provider defaults
  function syncProviderDefaults(){
    var p = $('#bai-provider').val();
    if(p === 'openai'){
      if(!$('#bai-api-base').val()) $('#bai-api-base').val('https://api.openai.com');
      if(!$('#bai-api-path').val()) $('#bai-api-path').val('/v1/chat/completions');
      if(!$('#bai-model').val()) $('#bai-model').val('gpt-4o-mini');
    } else if(p === 'deepseek'){
      if(!$('#bai-api-base').val() || $('#bai-api-base').val().indexOf('openai')>=0) $('#bai-api-base').val('https://api.deepseek.com');
      if(!$('#bai-api-path').val()) $('#bai-api-path').val('/v1/chat/completions');
      if(!$('#bai-model').val() || $('#bai-model').val().indexOf('gpt')===0) $('#bai-model').val('deepseek-chat');
    }
  }
  $('#bai-provider').on('change', syncProviderDefaults); syncProviderDefaults();

  // Prompt templates (posts)
  var $postTpl = $('#bai-post-template-select');
  var $postPrompt = $('#bai-system-prompt');
  function applyPostTpl(choice, clear){
    if(!$postPrompt.length) return;
    if(choice==='custom'){ if(clear) $postPrompt.val(''); return; }
    if(BAISlug && BAISlug.templates && typeof BAISlug.templates[choice]==='string'){
      $postPrompt.val(BAISlug.templates[choice]);
    }
  }
  if($postTpl.length){
    $postTpl.on('change', function(){ applyPostTpl($(this).val(), true); });
    var init = $postTpl.val();
    var cur = $.trim($postPrompt.val()||'');
    if(init && init!=='custom'){
      var str = (BAISlug.templates && BAISlug.templates[init]) || '';
      if(!cur){ applyPostTpl(init, false); }
      else if($.trim(str) && cur !== $.trim(str)){ $postTpl.val('custom'); }
    } else if(!init || init==='custom'){
      if(!cur){ var def=(BAISlug.lang==='en')?'en-default':'zh-default'; if(BAISlug.templates && BAISlug.templates[def]){ $postTpl.val(def); applyPostTpl(def,false); } }
    }
  }

  // Prompt templates (terms)
  var $termTpl = $('#bai-term-template-select');
  var $termPrompt = $('#bai-taxonomy-system-prompt');
  function applyTermTpl(choice, clear){
    if(!$termPrompt.length) return;
    if(choice==='custom'){ if(clear) $termPrompt.val(''); return; }
    if(BAISlug && BAISlug.templates && typeof BAISlug.templates[choice]==='string'){
      $termPrompt.val(BAISlug.templates[choice]);
    }
  }
  if($termTpl.length){
    $termTpl.on('change', function(){ applyTermTpl($(this).val(), true); });
    var initT = $termTpl.val();
    var curT = $.trim($termPrompt.val()||'');
    if(initT && initT!=='custom'){
      var strT = (BAISlug.templates && BAISlug.templates[initT]) || '';
      if(!curT){ applyTermTpl(initT, false); }
      else if($.trim(strT) && curT !== $.trim(strT)){ $termTpl.val('custom'); }
    }
  }

  // Site Topic customize/reset
  var $siteTopic = $('#bai-site-topic');
  var $siteTopicMode = $('#bai-site-topic-mode');
  var $btnSiteCustom = $('#bai-site-topic-customize');
  var $btnSiteReset = $('#bai-site-topic-reset');
  var $siteTopicTip = $('#bai-site-topic-tip');
  function setSiteTopicMode(mode){
    $siteTopicMode.val(mode);
    if(mode==='auto'){
      $siteTopic.prop('readonly', true);
      if($siteTopicTip.length){ $siteTopicTip.text(BAISlug.i18n.site_topic_auto_tip || ''); }
      $btnSiteCustom.data('editing', false).text(BAISlug.i18n.site_topic_customize || '自定义');
    } else {
      $siteTopic.prop('readonly', false);
      if($siteTopicTip.length){ $siteTopicTip.text(''); }
    }
  }
  if($siteTopic.length){ setSiteTopicMode((BAISlug.siteTopic && BAISlug.siteTopic.mode) || ($siteTopicMode.val() || 'auto')); }
  $btnSiteCustom.on('click', function(){
    if(!$siteTopic.length) return;
    var editing = !!$btnSiteCustom.data('editing');
    if(!editing){ setSiteTopicMode('custom'); $btnSiteCustom.data('editing', true).text(BAISlug.i18n.site_topic_save || '保存'); $siteTopic.focus(); }
    else {
      var val = $.trim($siteTopic.val()||'');
      api({ action:'bai_site_topic', nonce: BAISlug.nonce, op:'set', mode:'custom', value: val }).done(function(res){
        if(res && res.success){ setSiteTopicMode('custom'); $btnSiteCustom.data('editing', false).text(BAISlug.i18n.site_topic_customize || '自定义'); }
      });
    }
  });
  $btnSiteReset.on('click', function(){ api({ action:'bai_site_topic', nonce: BAISlug.nonce, op:'set', mode:'auto' }).done(function(res){ if(res && res.success){ var v=(res.data&&res.data.site_topic)||''; if($siteTopic.length){ $siteTopic.val(v); } setSiteTopicMode('auto'); } }); });

  // Tabs switching
  $('#bai-tabs .nav-tab').each(function(){ var href=$(this).attr('href'); if(href && href.indexOf('#tab-')===0){ $(this).attr('data-target', href.substring(1)).attr('href','javascript:;'); } });
  var savedTab = localStorage.getItem('bai_active_tab'); if(savedTab && $('#'+savedTab).length){ $('#bai-tabs .nav-tab').removeClass('nav-tab-active'); $('#bai-tabs .nav-tab[data-target="'+savedTab+'"]').addClass('nav-tab-active'); $('.bai-tab').hide(); $('#'+savedTab).show(); }
  $('#bai-tabs').on('click', '.nav-tab', function(e){ e.preventDefault(); var target=$(this).data('target'); if(!target){ var href=$(this).attr('href')||''; if(href.indexOf('#')===0){ target=href.substring(1); } } if(!target) return; $('#bai-tabs .nav-tab').removeClass('nav-tab-active'); $(this).addClass('nav-tab-active'); $('.bai-tab').hide(); $('#'+target).show(); localStorage.setItem('bai_active_tab', target); });

  // Language quick switch
  $(document).on('change', '#bai-lang-switch', function(){ var lang=$(this).val(); api({ action:'bai_set_lang', nonce: BAISlug.nonce, lang: lang }).always(function(){ location.reload(); }); });

  // Save+Test connectivity using current form values
  $('#bai-test-connectivity').on('click', function(){
    var $btn=$(this); $btn.prop('disabled', true).text(BAISlug.i18n.testing);
    $('#bai-test-result').hide().removeClass('notice-success notice-error').text('');
    var options={
      provider: $('#bai-provider').val(), api_base: $('#bai-api-base').val(), api_path: $('#bai-api-path').val(), api_key: $('#bai-api-key').val(), model: $('#bai-model').val(),
      // No direct max length; prompt controls length
      prompt_template_choice: $postTpl.length ? $postTpl.val() : 'custom',
      taxonomy_prompt_template_choice: $termTpl.length ? $termTpl.val() : 'custom',
      system_prompt: ($postPrompt.val()||''), taxonomy_system_prompt: ($termPrompt.val()||''),
      site_topic: $('#bai-site-topic').val(), site_topic_mode: ($('#bai-site-topic').is('[readonly]') ? 'auto' : 'custom'),
      use_glossary: $('input[name="use_glossary"]').is(':checked') ? 1 : 0,
      glossary_text: $('textarea[name="glossary_text"]').val(),
      enabled_post_types: $('input[name^="enabled_post_types"]').map(function(){return this.checked?this.value:null;}).get().filter(Boolean),
      enabled_taxonomies: $('input[name^="enabled_taxonomies"]').map(function(){return this.checked?this.value:null;}).get().filter(Boolean),
      strict_mode: $('#bai-strict-mode').is(':checked') ? 1 : 0
    };
    // 模拟正式调用：服务端保存并实时向模型发送“Hi”验证返回
    api({ action:'bai_save_and_test', nonce: BAISlug.nonce, options: options })
      .done(function(res){ var ok=res && res.success; var msg=(res && res.data && res.data.message) ? res.data.message : (ok?'OK':'Failed'); $('#bai-test-result').addClass(ok?'notice-success':'notice-error').text(msg).show(); })
      .fail(function(){ $('#bai-test-result').addClass('notice-error').text(BAISlug.i18n.request_failed).show(); })
      .always(function(){ $btn.prop('disabled', false).text(BAISlug.i18n.test); });
  });

  // Auto toggles
  $(document).on('change', '#bai-auto-posts', function(){ api({ action:'bai_set_flags', nonce: BAISlug.nonce, options: { auto_generate_posts: $(this).is(':checked')?1:0 } }); });
  $(document).on('change', '#bai-auto-terms', function(){ api({ action:'bai_set_flags', nonce: BAISlug.nonce, options: { auto_generate_terms: $(this).is(':checked')?1:0 } }); });

  // 分类法处理列表
  var $taxSel = $('#bai-terms-tax'); var $attrSel=$('#bai-terms-attr'); var $perInp=$('#bai-terms-per'); var $tbody=$('#bai-terms-tbody'); var $pager=$('#bai-terms-pagination');
  function buildTaxOptions(){ if(!$taxSel.length) return; $taxSel.empty(); $taxSel.append('<option value="all">全部</option>'); var list=(BAISlug.taxonomies||[]); list.forEach(function(t){ $taxSel.append('<option value="'+t.name+'">'+t.label+'</option>'); }); }
  buildTaxOptions();
  function renderRows(items){ var html=''; if(!items||!items.length){ html='<tr><td colspan="8">无数据</td></tr>'; } else { items.forEach(function(it){ html+='<tr data-id="'+it.id+'" data-tax="'+it.taxonomy+'">'+
      '<td><input type="checkbox" class="bai-term-select" value="'+it.id+'"></td>'+
      '<td>'+it.id+'</td>'+
      '<td class="col-title"><span class="text">'+$('<div/>').text(it.name).html()+'</span></td>'+
      '<td>'+it.taxonomy+'</td>'+
      '<td class="col-slug"><code>'+($('<div/>').text(it.slug).html())+'</code></td>'+
      '<td class="col-attr">'+(it.attr||'—')+'</td>'+
      '<td class="col-proposed">'+(it.proposed?('<span class="status-proposed"><code>'+($('<div/>').text(it.proposed).html())+'</code></span>'):'')+'</td>'+
      '<td class="col-actions">'
        +'<button class="button bai-term-gen">'+(BAISlug.i18n.generate||'生成')+'</button> '
        +'<button class="button button-primary bai-term-accept">应用</button> '
        +'<button class="button bai-term-reject">拒绝</button>'
      +'</td>'+
    '</tr>'; }); }
    $tbody.html(html);
  }
  function renderPager(total, per, paged){
    var pages=Math.max(1, Math.ceil(total/per));
    var html='';
    function add(num, current){ if(num===current) html+='<span class="page-numbers current">'+num+'</span>'; else html+='<a href="javascript:;" class="page-numbers" data-page="'+num+'">'+num+'</a>'; }
    if(pages<=12){ for(var i=1;i<=pages;i++){ add(i,paged); } }
    else {
      // 1 2 3 4 5 ... (paged-1) paged (paged+1) ... (pages-4 .. pages)
      var head=[1,2,3,4,5]; var tail=[pages-4,pages-3,pages-2,pages-1,pages];
      var set=new Set(); head.forEach(function(n){ if(n>=1&&n<=pages) set.add(n); });
      for(var d=-1; d<=1; d++){ var n=paged+d; if(n>=1&&n<=pages) set.add(n); }
      tail.forEach(function(n){ if(n>=1&&n<=pages) set.add(n); });
      var arr=Array.from(set).sort(function(a,b){return a-b;});
      var prev=0;
      arr.forEach(function(n){ if(prev && n-prev>1){ html+='<span class="page-numbers dots">…</span>'; } add(n,paged); prev=n; });
    }
    $pager.html(html);
  }
  function fetchTerms(paged){ if(!$tbody.length) return; paged = paged || 1; $tbody.html('<tr><td colspan="8">加载中…</td></tr>'); api({ action:'bai_terms_list', nonce: BAISlug.nonce, tax: $taxSel.val()||'all', attr: $attrSel.val()||'', per_page: parseInt($perInp.val()||'20',10), paged: paged, s: ($('#bai-terms-search').val()||'') }).done(function(res){ if(res && res.success){ var data=res.data||{}; renderRows(data.items||[]); renderPager(parseInt(data.total||0,10), parseInt(data.per_page||20,10), parseInt(data.paged||1,10)); } else { $tbody.html('<tr><td colspan="8">'+(BAISlug.i18n.request_failed||'请求失败')+'</td></tr>'); } }).fail(function(){ $tbody.html('<tr><td colspan="8">'+(BAISlug.i18n.request_failed||'请求失败')+'</td></tr>'); }); }
  $('#bai-terms-refresh').on('click', function(){ fetchTerms(1); });
  $('#bai-terms-tax, #bai-terms-attr').on('change', function(){ fetchTerms(1); });
  $(document).on('keypress', '#bai-terms-search', function(e){ if(e.which===13){ e.preventDefault(); fetchTerms(1); } });
  $pager.on('click', 'a.page-numbers', function(){ var p=parseInt($(this).data('page')||'1',10); fetchTerms(p); });
  $(document).on('change', '#bai-terms-select-all, #bai-terms-select-all-top', function(){ var checked=$(this).is(':checked'); $('input.bai-term-select').prop('checked', checked); });

  function selectedTermIds(){ return $('input.bai-term-select:checked').map(function(){ return parseInt(this.value,10)||0; }).get().filter(Boolean); }
  function termRow($tr){ return { id: parseInt($tr.data('id')||'0',10)||0, tax: $tr.data('tax')||'' }; }

  // Row actions
  $tbody.on('click', '.bai-term-gen', function(e){ e.preventDefault(); var $tr=$(this).closest('tr'); var id=termRow($tr).id; var $cell=$tr.find('.col-proposed'); $cell.find('.status-proposed').remove(); $(this).prop('disabled', true); api({ action:'bai_term_generate_one', nonce: BAISlug.nonce, term_id:id }).done(function(res){ if(res && res.success && res.data && res.data.slug){ $cell.append('<span class="status-proposed"><code>'+res.data.slug+'</code></span>'); } }).always(function(){ $('.bai-term-gen', $tr).prop('disabled', false); }); });
  $tbody.on('click', '.bai-term-accept', function(e){ e.preventDefault(); var $tr=$(this).closest('tr'); var id=termRow($tr).id; var $btn=$(this).prop('disabled', true); api({ action:'bai_term_apply', nonce: BAISlug.nonce, ids:[id] }).done(function(res){ if(res && res.success){ var rr=(res.data&&res.data.result&&res.data.result[id])||{}; if(rr.ok){ $tr.find('.col-slug code').text(rr.slug); $tr.find('.col-attr').text('ai'); $tr.find('.col-proposed .status-proposed').remove(); } } }).always(function(){ $btn.prop('disabled', false); }); });
  $tbody.on('click', '.bai-term-reject', function(e){ e.preventDefault(); var $tr=$(this).closest('tr'); var id=termRow($tr).id; var $btn=$(this).prop('disabled', true); api({ action:'bai_term_reject', nonce: BAISlug.nonce, ids:[id] }).done(function(res){ if(res && res.success){ $tr.find('.col-proposed .status-proposed').remove(); } }).always(function(){ $btn.prop('disabled', false); }); });

  // Bulk actions
  $('#bai-term-generate-selected').on('click', function(){ var ids=selectedTermIds(); if(!ids.length) return; var i=0; var $btn=$(this).prop('disabled', true); (function next(){ if(i>=ids.length){ $btn.prop('disabled', false); return; } var id=ids[i++]; var $tr=$('tr[data-id="'+id+'"]'); var $cell=$tr.find('.col-proposed'); $cell.find('.status-proposed').remove(); api({ action:'bai_term_generate_one', nonce: BAISlug.nonce, term_id:id }).done(function(res){ if(res && res.success && res.data && res.data.slug){ $cell.append('<span class="status-proposed"><code>'+res.data.slug+'</code></span>'); } }).always(next); })(); });
  $('#bai-term-apply-selected').on('click', function(){ var ids=selectedTermIds(); if(!ids.length) return; var $btn=$(this).prop('disabled', true); api({ action:'bai_term_apply', nonce: BAISlug.nonce, ids: ids }).done(function(res){ if(res && res.success){ var map=res.data&&res.data.result||{}; ids.forEach(function(id){ var rr=map[id]; if(rr && rr.ok){ var $tr=$('tr[data-id="'+id+'"]'); $tr.find('.col-slug code').text(rr.slug); $tr.find('.col-attr').text('ai'); $tr.find('.col-proposed .status-proposed').remove(); $tr.find('input.bai-term-select').prop('checked', false); } }); } }).always(function(){ $btn.prop('disabled', false); }); });
  $('#bai-term-reject-selected').on('click', function(){ var ids=selectedTermIds(); if(!ids.length) return; var $btn=$(this).prop('disabled', true); api({ action:'bai_term_reject', nonce: BAISlug.nonce, ids: ids }).done(function(res){ if(res && res.success){ ids.forEach(function(id){ var $tr=$('tr[data-id="'+id+'"]'); $tr.find('.col-proposed .status-proposed').remove(); $tr.find('input.bai-term-select').prop('checked', false); }); } }).always(function(){ $btn.prop('disabled', false); }); });

  // Initial load for terms list if tab exists
  if($('#bai-terms-tbody').length){ fetchTerms(1); }
});
