/** Converts #rrggbb to rgba(r,g,b,a) */
function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}




/**
 * Initialize Everything
 */
document.addEventListener("DOMContentLoaded", function () {
    
});