import axios from 'https://cdn.skypack.dev/axios';

import { TOKEN } from "./token.js";

const instance = ({
	baseURL = "",
	headers = { "X-WP-Nonce": TOKEN || "" },
} = {}) =>
	axios.create({
		baseURL: baseURL,
		headers: {
			"Content-Type": "application/json",
			...headers,
		},
	});

// Use for regular WP REST routes
const wp_base = instance({
	baseURL: window?.global_vars?.rest_base,
});

const wp_acf = instance({
	baseURL: "/wp-json/acf/v3",
});

const adbuilder = instance({
  baseURL: "/wp-json/lsa-adbuilder/v1",
});

const forecasts = instance({
  baseURL: "/wp-json/lsa-adbuilder/v1/forecasts",
});

// const wp_dist = instance({
// 	baseURL: "/wp-json/locations/v1",
// });

export { wp_base, wp_acf, adbuilder };
