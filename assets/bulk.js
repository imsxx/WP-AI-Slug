jQuery(function($){
  var running = false;
  var pollTimer = null;
  // Dedupe trackers for post logs and term logs
  var seenLogs = Object.create(null);
  var seenLogsCount = 0;
  var seenTermsLogs = Object.create(null);
  var seenTermsLogsCount = 0;
  var cursorEl = $('#bai-cursor');
  var processedEl = $('#bai-processed');
  var scannedEl = $('#bai-scanned');
  var logEl = $('#bai-log');
  // Terms elements
  var runningTerms = false;
  var logTermsEl = $('#bai-log-terms');
  var termsProcessedEl = $('#bai-terms-processed');
  var termsScannedEl = $('#bai-terms-scanned');
  var termsCursorEl = $('#bai-terms-cursor');

  function log(msg){ if(!msg) return; logEl.prepend($('<p/>').text('· '+msg)); }
  function collectStart(){
    var pts=[]; $('.bai-pt:checked').each(function(){ pts.push($(this).val()); });
    // optional controls
    var scheme = $('input[name="bai-scheme"]:checked').val() || 'title';
    var custom_prompt = $('#bai-scheme-custom-text').val() || '';
    var delimiter = $('input[name="bai-delim"]:checked').val() || '-';
    var delimiter_custom = $('#bai-delim-custom-text').val() || '';
    var collision = $('input[name="bai-collision"]:checked').val() || 'append_date';
    return {
      action: 'bai_slug_queue_start',
      nonce: BAISlugBulk.nonce,
      batch_size: parseInt($('#bai-batch-size').val()||'5',10),
      post_types: pts,
      skip_ai: $('#bai-skip-ai').is(':checked') ? 1 : 0,
      scheme: scheme,
      custom_prompt: custom_prompt,
      delimiter: delimiter,
      delimiter_custom: delimiter_custom,
      collision: collision
    };
  }
  function req(action){ return { action: action, nonce: BAISlugBulk.nonce }; }
  function toggle(run){
    running=run;
    $('#bai-start').prop('disabled',run);
    $('#bai-stop').prop('disabled',!run);
    if(!run && pollTimer){ clearTimeout(pollTimer); pollTimer=null; }
  }
  function renderProgress(d){
    processedEl.text(d.processed||0);
    scannedEl.text(d.total||0);
    cursorEl.text(d.cursor||0);
    if(Array.isArray(d.log)){
      // Render latest up to 10 lines
      var items = d.log.slice(0,10);
      items.forEach(function(l){
        if(!l) return;
        if(!seenLogs[l]){
          seenLogs[l] = true;
          seenLogsCount++;
          // Prevent unbounded growth
          if(seenLogsCount > 500){ seenLogs = Object.create(null); seenLogsCount = 0; }
          log(l);
        }
      });
    }
    if(d.done){ log(BAISlugBulk.i18n.done); toggle(false); }
  }

  function renderTermsProgress(d){
    termsProcessedEl.text(d.processed||0);
    termsScannedEl.text(d.total||0);
    termsCursorEl.text(d.cursor||0);
    if(Array.isArray(d.log)){
      var items = d.log.slice(0,10);
      items.forEach(function(l){
        if(!l) return;
        if(!seenTermsLogs[l]){
          seenTermsLogs[l] = true;
          seenTermsLogsCount++;
          if(seenTermsLogsCount > 500){ seenTermsLogs = Object.create(null); seenTermsLogsCount = 0; }
          logTermsEl.prepend($('<p/>').text('· '+l));
        }
      });
    }
    if(d.done){ log(BAISlugBulk.i18n.done); runningTerms=false; }
  }
  function poll(){
    if(!running) return;
    $.post(BAISlugBulk.ajax_url, req('bai_slug_queue_progress'))
      .done(function(res){
        if(!res||!res.success){
          var msg = (res && res.data && res.data.message) ? res.data.message : BAISlugBulk.i18n.request_failed;
          log(msg); toggle(false); return;
        }
        renderProgress(res.data||{});
        if(running) pollTimer = setTimeout(poll, 1500);
      })
      .fail(function(jq){
        var info = (jq && jq.status ? ('HTTP '+jq.status+' ') : '') + (jq && jq.statusText ? jq.statusText : '');
        log(BAISlugBulk.i18n.network_error + (info ? (': '+info) : ''));
        toggle(false);
      });
  }

  $('#bai-start').on('click', function(){
    processedEl.text('0'); scannedEl.text('0'); logEl.empty();
    seenLogs = Object.create(null); seenLogsCount = 0;
    $.post(BAISlugBulk.ajax_url, collectStart())
      .done(function(res){
        if(!res||!res.success){
          var msg = (res && res.data && res.data.message) ? res.data.message : BAISlugBulk.i18n.request_failed; log(msg); return;
        }
        toggle(true); renderProgress(res.data||{}); poll();
      })
      .fail(function(jq){ var info=(jq&&jq.status?('HTTP '+jq.status+' '):'')+(jq&&jq.statusText?jq.statusText:''); log(BAISlugBulk.i18n.network_error+(info?(': '+info):'')); });
  });

  $('#bai-stop').on('click', function(){
    $.post(BAISlugBulk.ajax_url, req('bai_slug_queue_pause')).always(function(){ toggle(false); });
  });

  $('#bai-reset').on('click', function(){
    $.post(BAISlugBulk.ajax_url, req('bai_slug_queue_reset')).done(function(res){
      if(res&&res.success&&res.data){ cursorEl.text(res.data.cursor||0); log(BAISlugBulk.i18n.cursor_reset); processedEl.text('0'); scannedEl.text('0'); logEl.empty(); }
    });
  });

  function collectTermsStart(){
    var taxes=[]; $('.bai-tax:checked').each(function(){ taxes.push($(this).val()); });
    return {
      action: 'bai_slug_terms_start',
      nonce: BAISlugBulk.nonce,
      batch_size: parseInt($('#bai-batch-size').val()||'5',10),
      taxonomies: taxes,
      skip_ai: $('#bai-terms-skip-ai').is(':checked') ? 1 : 0
    };
  }
  function reqTerms(action){ return { action: action, nonce: BAISlugBulk.nonce }; }
  function pollTerms(){
    if(!runningTerms) return;
    $.post(BAISlugBulk.ajax_url, reqTerms('bai_slug_terms_progress'))
      .done(function(res){
        if(!res||!res.success){
          var msg = (res && res.data && res.data.message) ? res.data.message : BAISlugBulk.i18n.request_failed;
          log(msg); runningTerms=false; return;
        }
        renderTermsProgress(res.data||{});
        if(runningTerms) setTimeout(pollTerms, 1500);
      })
      .fail(function(jq){ var info=(jq&&jq.status?('HTTP '+jq.status+' '):'')+(jq&&jq.statusText?jq.statusText:''); log(BAISlugBulk.i18n.network_error+(info?(': '+info):'')); runningTerms=false; });
  }
  $('#bai-terms-start').on('click', function(){
    termsProcessedEl.text('0'); termsScannedEl.text('0'); if(logTermsEl) logTermsEl.empty();
    seenTermsLogs = Object.create(null); seenTermsLogsCount = 0;
    $.post(BAISlugBulk.ajax_url, collectTermsStart())
      .done(function(res){ if(!res||!res.success){ var msg=(res&&res.data&&res.data.message)?res.data.message:BAISlugBulk.i18n.request_failed; log(msg); return; }
        runningTerms=true; renderTermsProgress(res.data||{}); pollTerms(); })
      .fail(function(jq){ var info=(jq&&jq.status?('HTTP '+jq.status+' '):'')+(jq&&jq.statusText?jq.statusText:''); log(BAISlugBulk.i18n.network_error+(info?(': '+info):'')); });
  });
  $('#bai-terms-reset').on('click', function(){
    $.post(BAISlugBulk.ajax_url, reqTerms('bai_slug_terms_reset')).done(function(res){
      if(res&&res.success&&res.data){ termsCursorEl.text(res.data.cursor||0); if(logTermsEl) logTermsEl.empty(); termsProcessedEl.text('0'); termsScannedEl.text('0'); log(BAISlugBulk.i18n.cursor_reset); }
    });
  });
});
