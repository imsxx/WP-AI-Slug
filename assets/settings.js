jQuery(function($){
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
  $('#bai-provider').on('change', syncProviderDefaults);
  syncProviderDefaults();

  // Hide max tokens row if exists (not necessary for slug use)
  $('#bai-max-tokens').closest('tr').hide();

  // Ensure glossary textarea has id for anchor
  $('textarea[name^="bai_slug_settings[glossary_text]"]').attr('id','bai-glossary-text');

  // Move the existing usage card into the Guide tab if present
  var guideCard = $('#guide').closest('.card');
  if (guideCard.length && $('#tab-guide').length) {
    guideCard.appendTo('#tab-guide');
  }

  // Inject Quick Start & FAQ into Guide tab if empty
  if ($('#tab-guide').length && !$('#tab-guide').data('enhanced')) {
    var $g = $('#tab-guide');
    if ($g.children().length === 0) {
      var html = ''+
        '<h2>快速开始</h2>'+
        '<ol style="padding-left:18px">'+
          '<li>在“基本设置”里选择提供商，填写 API 基址、接口路径、API Key 和模型。</li>'+
          '<li>在“术语表”中按行添加固定翻译（可选），以便处理专有名词。</li>'+
          '<li>勾选要自动生成 slug 的文章类型与分类法。</li>'+
          '<li>点击“测试连通性”。成功后，新建/保存文章与术语将自动生成英文 slug。</li>'+
          '<li>历史数据可用“异步批量”处理；也可在“手动编辑”中集中编辑。</li>'+
        '</ol>'+
        '<h2>常见说明</h2>'+
        '<p><strong>哪些会被处理</strong><br/>文章/标签等：新建、保存时自动生成；已有内容若 slug 为默认值（为空或等于标题的标准化结果）会在批量时被替换。</p>'+
        '<p><strong>游标是什么</strong><br/>代表扫描到的最后 ID，便于中断后继续。</p>'+
        '<p><strong>术语表怎么写</strong><br/>每行“原词=翻译”或“原词|翻译”。当标题包含原词时，提示模型使用该翻译。仅作为提示，最终仍会过 sanitize_title。</p>'+
        '<p><strong>如何采纳/拒绝建议</strong><br/>在“手动编辑”页可以生成建议，并按行接受或拒绝。</p>'+
        '<p>源码地址：<a href="https://github.com/imsxx/wp-ai-slug" target="_blank" rel="noopener">https://github.com/imsxx/wp-ai-slug</a></p>';
      $g.append(html).data('enhanced', true);
    }
  }

  // Tabs switching (supports data-target or href="#tab-xxx")
  // Normalize nav anchors to data-target
  $('#bai-tabs .nav-tab').each(function(){
    var href = $(this).attr('href');
    if(href && href.indexOf('#tab-')===0){
      $(this).attr('data-target', href.substring(1)).attr('href','javascript:;');
    }
  });
  // restore active tab from storage
  var savedTab = localStorage.getItem('bai_active_tab');
  if(savedTab && $('#'+savedTab).length){
    $('#bai-tabs .nav-tab').removeClass('nav-tab-active');
    $('#bai-tabs .nav-tab[data-target="'+savedTab+'"]').addClass('nav-tab-active');
    $('.bai-tab').hide();
    $('#'+savedTab).show();
  }
  $('#bai-tabs').on('click', '.nav-tab', function(e){
    e.preventDefault();
    var target = $(this).data('target');
    if(!target){
      var href = $(this).attr('href') || '';
      if(href.indexOf('#') === 0){ target = href.substring(1); }
    }
    if(!target){ return; }
    $('#bai-tabs .nav-tab').removeClass('nav-tab-active');
    $(this).addClass('nav-tab-active');
    $('.bai-tab').hide();
    $('#'+target).show();
    localStorage.setItem('bai_active_tab', target);
  });

  // Move glossary fields into Glossary tab table (match actual field names)
  if($('#tab-glossary').length){
    if($('#tab-glossary-table').length===0){
      $('#tab-glossary').append('<table class="form-table" id="tab-glossary-table"></table>');
    }
    var $glossaryTable = $('#tab-glossary-table');
    var $rowUse = $('input[name="use_glossary"]').closest('tr');
    var $rowText = $('textarea[name="glossary_text"]').closest('tr');
    if($rowUse.length){ $glossaryTable.append($rowUse); }
    if($rowText.length){ $rowText.find('textarea').attr('id','bai-glossary-text'); $glossaryTable.append($rowText); }
  }

  // Remove legacy UI language field from basic form (we have top selector)
  $('select[name^="bai_slug_settings[ui_lang]"]').closest('tr').remove();

  // Quick language switch
  $(document).on('change', '#bai-lang-switch', function(){
    var lang = $(this).val();
    $.post(BAISlug.ajax_url, { action:'bai_set_lang', nonce: BAISlug.nonce, lang: lang })
      .always(function(){ location.reload(); });
  });

  $('#bai-test-connectivity').on('click', function(){
    var $btn = $(this);
    $btn.prop('disabled', true).text(BAISlug.i18n.testing);
    $('#bai-test-result').hide().removeClass('notice-success notice-error').text('');
    // Collect current form values and send to save+test
    var options = {
      provider: $('#bai-provider').val(),
      api_base: $('#bai-api-base').val(),
      api_path: $('#bai-api-path').val(),
      api_key: $('#bai-api-key').val(),
      model: $('#bai-model').val(),
      slug_max_chars: parseInt($('input[name^="slug_max_chars"]').val()||'60',10),
      system_prompt: $('textarea[name^="system_prompt"]').val(),
      use_glossary: $('input[name^="use_glossary"]').is(':checked') ? 1 : 0,
      glossary_text: $('textarea[name="glossary_text"]').val(),
      enabled_post_types: $('input[name^="enabled_post_types"]:checked').map(function(){return this.value;}).get(),
      enabled_taxonomies: $('input[name^="enabled_taxonomies"]:checked').map(function(){return this.value;}).get()
    };
    $.post(BAISlug.ajax_url, { action:'bai_save_and_test', nonce: BAISlug.nonce, options: options })
      .done(function(res){
        var ok = res && res.success;
        var msg = (res && res.data && res.data.message) ? res.data.message : 'Unknown result';
        $('#bai-test-result').addClass(ok?'notice-success':'notice-error').text(msg).show();
      })
      .fail(function(){
        $('#bai-test-result').addClass('notice-error').text(BAISlug.i18n.request_failed).show();
      })
      .always(function(){ $btn.prop('disabled', false).text(BAISlug.i18n.test); });
  });
});

