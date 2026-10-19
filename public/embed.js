(function () {
  if (window.__tytoEmbedLoaded) return;
  window.__tytoEmbedLoaded = true;

  var script = document.currentScript || document.querySelector('script[src$="/embed.js"]');
  var origin = script ? new URL(script.src).origin : null;
  if (!origin) return;

  var frames = Object.create(null);

  function process() {
    var quotes = document.querySelectorAll(
      "blockquote.tyto-embed[data-tyto-message]:not([data-tyto-processed])",
    );
    for (var i = 0; i < quotes.length; i++) {
      var q = quotes[i];
      var uuid = q.getAttribute("data-tyto-message");
      if (!uuid) continue;
      q.setAttribute("data-tyto-processed", "");
      var iframe = document.createElement("iframe");
      iframe.src = origin + "/embed/m/" + encodeURIComponent(uuid);
      iframe.loading = "lazy";
      iframe.title = "tyto message";
      iframe.style.border = "0";
      iframe.style.width = "100%";
      iframe.style.maxWidth = "550px";
      iframe.style.height = "160px";
      frames[uuid] = iframe;
      q.parentNode.replaceChild(iframe, q);
    }
  }

  window.addEventListener("message", function (e) {
    if (e.origin !== origin) return;
    var d = e.data;
    if (!d || d.type !== "tyto-embed-height") return;
    var frame = frames[d.uuid];
    if (!frame || e.source !== frame.contentWindow) return;
    if (typeof d.height === "number" && d.height > 0 && d.height < 4000) {
      frame.style.height = d.height + "px";
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", process);
  } else {
    process();
  }
})();
