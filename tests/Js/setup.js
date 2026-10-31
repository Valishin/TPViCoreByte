// jsdom no trae matchMedia: la caja (≥ 1024 px en horizontal) por defecto.
window.matchMedia ??= (query) => ({
    matches: query.includes('min-width: 1024px'),
    media: query,
    addEventListener() {},
    removeEventListener() {},
    addListener() {},
    removeListener() {},
});
