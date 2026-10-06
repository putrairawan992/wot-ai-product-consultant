#!/usr/bin/env python3
"""Build products.json from WOT sitemap - slug-to-name conversion, no page scraping."""
import re, json, urllib.request

SITEMAP_URL = "https://www.worldoftest.com/products/sitemap.xml"

CATEGORIES = {
    "Metal Testing Equipment": [
        "hardness","rockwell","brinell","vickers","knoop","charpy","izod-metal",
        "impact-test","fatigue","tensile","spectrometer","oes","xrf","metal",
        "steel","creep","torsion","bend","notch","jominy","cupping","erichsen",
        "sheet-metal","flattening","flaring","drift","crush","ring","compression",
        "wear","abrasion","friction","tribometer","scratch","pendulum",
    ],
    "Plastic Testing Equipment": [
        "plastic","mfi","melt-flow","melt-index","vicat","heat-deflect",
        "shore","durometer","rubber","elastomer","polymer","resin",
        "flexural","flex-","tensile-plastic","elongation","film-tensile",
        "tear","puncture","burst","compression-set","stress-relax",
    ],
    "Plastics PVC / Pipe Testing": [
        "pipe","pvc","tubing","tube-","hose","fitting","hydrostatic",
        "pressure-pipe","ring-stiffness","ring-flexibility","flattening-pipe",
        "impact-pipe","wall-thickness","ovality",
    ],
    "Environmental Chambers": [
        "chamber","climate","climatic","temperature","humidity","thermal",
        "aging-","uv-aging","uv-weathering","salt-spray","salt-fog","corrosion",
        "xenon","carbon-arc","ozone","immersion","freeze-thaw","thermal-shock",
        "altitude","vacuum","walk-in","reach-in",
    ],
    "Color, Paint & Coating Testing": [
        "color","colour","paint","coating","adhesion","gloss","glossmeter",
        "colorimeter","spectrophotometer-color","film-thickness","dft",
        "viscosity","viscometer","rheometer","flow-cup","ford-cup",
        "konig","persoz","pendulum-hardness","cross-cut","tape",
        "mar-resistance","impact-paint","bend-paint","mandrel","wedge",
    ],
}

def slug_to_name(url: str) -> str:
    slug = url.rstrip("/").split("/")[-1]
    # remove common prefixes like "product-" or "qualixxx-"
    name = slug.replace("-", " ").title()
    return name

def assign_category(url: str, name: str) -> str | None:
    text = (url + " " + name).lower()
    for cat, keywords in CATEGORIES.items():
        if any(kw in text for kw in keywords):
            return cat
    return None

def main():
    print("Loading sitemap from /tmp/wot_sitemap.xml...")
    xml = open("/tmp/wot_sitemap.xml").read()

    urls = re.findall(r"<loc>([^<]+)</loc>", xml)
    print(f"Total URLs: {len(urls)}")

    # Filter: skip category/list pages, keep product pages
    product_urls = [u for u in urls if u.count("/") >= 4 or "/product/" in u]
    print(f"Product URLs: {len(product_urls)}")

    data = {cat: [] for cat in CATEGORIES}
    unmatched = []

    for url in product_urls:
        name = slug_to_name(url)
        cat = assign_category(url, name)
        if cat:
            data[cat].append({"name": name, "url": url})
        else:
            unmatched.append({"name": name, "url": url})

    for cat, products in data.items():
        print(f"  {cat}: {len(products)} products")
    print(f"  Unmatched: {len(unmatched)}")

    output = {"categories": data}
    with open("products.json", "w") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)
    print("Saved products.json")

if __name__ == "__main__":
    main()
