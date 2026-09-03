import Tesseract from 'tesseract.js';
import fs from 'fs';

const imagePath = process.argv[2];
if (!imagePath || !fs.existsSync(imagePath)) {
    console.log(JSON.stringify({ success: false, message: 'Image path not provided or does not exist.' }));
    process.exit(0);
}

function parseOnuText(rawText) {
    let mac = '';
    let ponSn = '';
    let sn = '';

    const clean = rawText.replace(/\r/g, '\n');
    const normalizeHex = (str) => {
        return String(str || '').toUpperCase()
            .replace(/[^A-F0-9]/g, '')
            .replace(/O/g, '0')
            .replace(/[IL]/g, '1')
            .replace(/S/g, '5')
            .replace(/B/g, '8')
            .replace(/Z/g, '2');
    };

    const normalizeAlphaNum = (str) => {
        return String(str || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    };

    // 1. EXTRACT MAC ADDRESS (supports same-line, next-line, colons, hyphens, spaces)
    const macRegexes = [
        /(?:MAC(?:\s*Address|\s*ID)?|WAN\s*MAC|LAN\s*MAC)[\s:]*([0-9A-Fa-f: -]{12,23})/i,
        /\b((?:4C|00|E0|F4|A0|50|74|80)[: -]?[0-9A-Fa-f]{2}[: -]?[0-9A-Fa-f]{2}[: -]?[0-9A-Fa-f]{2}[: -]?[0-9A-Fa-f]{2}[: -]?[0-9A-Fa-f]{2})\b/i,
        /\b(4C46D1[0-9A-Fa-f]{6})\b/i
    ];

    for (const r of macRegexes) {
        const m = clean.match(r);
        if (m && m[1]) {
            const candidate = normalizeHex(m[1]);
            if (candidate.length >= 12) {
                mac = candidate.substring(0, 12);
                break;
            }
        }
    }

    // 2. EXTRACT PON S/N / GPON SN (supports VSOL, HWTC, ZTEG, ALCL, FHTT, GMID)
    const ponRegexes = [
        /(?:G?PON(?:\s*S\/?N|\s*NO)?|G-?PON)[\s:]*([0-9A-Za-z\s]{8,18})/i,
        /\b(VS[0O]L[0-9A-Za-z\s]{6,10})\b/i,
        /\b(ZTEG[0-9A-Za-z]{8}|HWTC[0-9A-Za-z]{8}|ALCL[0-9A-Za-z]{8}|FHTT[0-9A-Za-z]{8}|48575443[0-9A-Za-z]{8})\b/i
    ];

    for (const r of ponRegexes) {
        const m = clean.match(r);
        if (m && m[1]) {
            let candidate = normalizeAlphaNum(m[1]);
            candidate = candidate.replace(/^VS[0O]L/, 'VSOL');
            if (candidate.startsWith('VSOL') && candidate.length >= 10) {
                ponSn = candidate.substring(0, 12);
                break;
            } else if (/^(ZTEG|HWTC|ALCL|FHTT|GMID)/.test(candidate) && candidate.length >= 12) {
                ponSn = candidate.substring(0, 12);
                break;
            } else if (candidate.length >= 12 && /^[0-9A-F]{12,16}$/.test(candidate) && !candidate.startsWith('4C46D1')) {
                ponSn = candidate.substring(0, 12);
            }
        }
    }

    // 3. EXTRACT SERIAL NUMBER (SN) (e.g. V25102320727 or S/N label)
    const snRegexes = [
        /(?:S\/?N|Serial(?:\s*Number|\s*NO)?|S\/NO)[\s:]*([0-9A-Za-z\s]{8,18})/i,
        /\b(V?2[3-6][0-9]{9,10})\b/i
    ];

    for (const r of snRegexes) {
        const m = clean.match(r);
        if (m && m[1]) {
            let candidate = normalizeAlphaNum(m[1]);
            if (/^[0-9]{10,12}$/.test(candidate)) {
                candidate = 'V' + candidate;
            }
            if (candidate !== mac && candidate !== ponSn) {
                sn = candidate;
                break;
            }
        }
    }

    // 4. SMART BI-DIRECTIONAL DERIVATION FOR V-SOL & OTHER GPON ONU
    if (ponSn && ponSn.startsWith('VSOL') && !mac) {
        mac = '4C46D1' + ponSn.substring(ponSn.length - 6);
    } else if (ponSn && ponSn.startsWith('HWTC') && !mac) {
        mac = '001882' + ponSn.substring(ponSn.length - 6);
    } else if (ponSn && ponSn.startsWith('ZTEG') && !mac) {
        mac = '001E73' + ponSn.substring(ponSn.length - 6);
    } else if (mac && mac.startsWith('4C46D1') && !ponSn) {
        ponSn = 'VSOL00' + mac.substring(6);
    } else if (mac && mac.startsWith('001882') && !ponSn) {
        ponSn = 'HWTC' + mac.substring(4);
    } else if (mac && mac.startsWith('001E73') && !ponSn) {
        ponSn = 'ZTEG' + mac.substring(4);
    }

    // If sn is still empty, fallback to pon_sn or formatted V-SN
    if (!sn) {
        if (ponSn) sn = ponSn;
        else if (mac) sn = mac;
    }

    return { mac, pon_sn: ponSn, serial_number_ont: sn };
}

Tesseract.recognize(imagePath, 'eng')
    .then(({ data: { text } }) => {
        const parsed = parseOnuText(text);
        console.log(JSON.stringify({
            success: true,
            mac: parsed.mac,
            pon_sn: parsed.pon_sn,
            serial_number_ont: parsed.serial_number_ont,
            raw_text: text
        }));
        process.exit(0);
    })
    .catch(err => {
        console.log(JSON.stringify({
            success: false,
            message: err.message || 'OCR Recognition Error'
        }));
        process.exit(0);
    });
