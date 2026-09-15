const REGION_ID = "hmw-event-listings-region";
const AJAX_ACTION = "hmwevents_filter_events";
const ATTS_PARAM = "hmw_atts";
const BASE_URL_PARAM = "hmw_base_url";
const FORM_SELECTOR = ".hmw-event-filters__form";

let activeController = null;

const region = document.getElementById(REGION_ID);

function ajaxUrl() {
  if (window.hmwevents_params && window.hmwevents_params.ajax_url) {
    return window.hmwevents_params.ajax_url;
  }
  return "/wp-admin/admin-ajax.php";
}

function filterForm() {
  return document.querySelector(FORM_SELECTOR);
}

function formAtt(form, name) {
  return form ? form.getAttribute(name) || "" : "";
}

function currentBaseUrl() {
  return window.location.origin + window.location.pathname;
}

function applyButton() {
  return document.querySelector(".hmw-event-filters__apply");
}

function setLoading(isLoading) {
  if (!region) {
    return;
  }
  if (isLoading) {
    region.setAttribute("aria-busy", "true");
  } else {
    region.removeAttribute("aria-busy");
  }
  const button = applyButton();
  if (button) {
    button.disabled = isLoading;
  }
}

async function requestFragment(params, options) {
  const { push = true, fallbackUrl = null } = options || {};

  if (activeController) {
    activeController.abort();
  }
  const controller = new AbortController();
  activeController = controller;

  setLoading(true);

  try {
    const response = await fetch(ajaxUrl() + "?" + params.toString(), {
      method: "GET",
      credentials: "same-origin",
      signal: controller.signal
    });

    const payload = await response.json();

    if (!payload || !payload.success || !payload.data || typeof payload.data.html !== "string") {
      throw new Error("Unexpected response from filter endpoint");
    }

    region.innerHTML = payload.data.html;

    if (push && payload.data.url) {
      window.history.pushState({}, "", payload.data.url);
    }

    if (push) {
      region.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  } catch (error) {
    if (error && error.name === "AbortError") {
      return;
    }
    if (fallbackUrl) {
      window.location.href = fallbackUrl;
    } else {
      window.location.reload();
    }
    return;
  } finally {
    if (activeController === controller) {
      activeController = null;
      setLoading(false);
    }
  }
}

function paramsFromForm(form) {
  const params = new URLSearchParams(new FormData(form));
  params.set("action", AJAX_ACTION);
  const atts = formAtt(form, "data-hmw-atts");
  if (atts) {
    params.set(ATTS_PARAM, atts);
  }
  params.set(BASE_URL_PARAM, formAtt(form, "data-hmw-base-url") || currentBaseUrl());
  params.delete("pg");
  return params;
}

function paramsFromUrl(url) {
  return paramsFromSearchParams(url.searchParams, url.origin + url.pathname);
}

function paramsFromSearchParams(searchParams, baseUrl) {
  const params = new URLSearchParams(searchParams);
  params.set("action", AJAX_ACTION);
  const atts = formAtt(filterForm(), "data-hmw-atts");
  if (atts) {
    params.set(ATTS_PARAM, atts);
  }
  params.set(BASE_URL_PARAM, baseUrl);
  params.delete("paged");
  return params;
}

function onSubmit(event) {
  const form = event.target.closest(FORM_SELECTOR);
  if (!form) {
    return;
  }

  event.preventDefault();

  const fallbackParams = new URLSearchParams(new FormData(form));
  const query = fallbackParams.toString();
  const fallbackUrl = currentBaseUrl() + (query ? "?" + query : "");

  requestFragment(paramsFromForm(form), { push: true, fallbackUrl: fallbackUrl });
}

function onRegionClick(event) {
  const link = event.target.closest(".hmw-pagination a, .hmw-active-filter__remove");
  if (!link || !region.contains(link)) {
    return;
  }

  const href = link.getAttribute("href");
  if (!href) {
    return;
  }

  event.preventDefault();

  let target;
  try {
    target = new URL(href, window.location.href);
  } catch (error) {
    window.location.href = href;
    return;
  }

  requestFragment(paramsFromUrl(target), { push: true, fallbackUrl: target.href });
}

function onPopState() {
  if (!region || !region.isConnected) {
    return;
  }

  const params = paramsFromSearchParams(
    new URLSearchParams(window.location.search),
    currentBaseUrl()
  );

  requestFragment(params, { push: false });
}

if (region && typeof window.fetch === "function") {
  document.addEventListener("submit", onSubmit);
  document.addEventListener("click", onRegionClick);
  window.addEventListener("popstate", onPopState);
}
