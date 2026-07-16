from __future__ import annotations

import json
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle


ROOT = Path(__file__).resolve().parents[1]
DATA_PATH = ROOT / "docs" / "product-backlog.json"
MARKDOWN_PATH = ROOT / "docs" / "product-backlog.md"
PDF_PATH = ROOT / "output" / "pdf" / "backlog_user_stories_revise.pdf"

NAVY = colors.HexColor("#1D3154")
GRID = colors.HexColor("#C2C7CF")
ZEBRA = colors.HexColor("#F0F1F3")
PRIORITY_COLORS = {
    "Haute": colors.HexColor("#D73A2F"),
    "Moyenne": colors.HexColor("#D98700"),
    "Faible": colors.HexColor("#2F8A38"),
}


def load_stories() -> list[dict[str, object]]:
    stories = json.loads(DATA_PATH.read_text(encoding="utf-8"))
    expected_ids = [f"US-{index:02d}" for index in range(1, len(stories) + 1)]
    actual_ids = [story["id"] for story in stories]
    if actual_ids != expected_ids:
        raise ValueError("User Story identifiers must be unique and sequential.")
    if any(story["priorite"] not in PRIORITY_COLORS for story in stories):
        raise ValueError("Every User Story must use Haute, Moyenne or Faible priority.")
    return stories


def write_markdown(stories: list[dict[str, object]]) -> None:
    total = sum(int(story["points"]) for story in stories)
    lines = [
        "# Backlog Produit - User Stories",
        "",
        f"{len(stories)} user stories - Total : {total} points",
        "",
        "| ID | User Story | Priorité | Points |",
        "|---|---|---:|---:|",
    ]
    lines.extend(
        f"| {story['id']} | {story['user_story']} | {story['priorite']} | {story['points']} |"
        for story in stories
    )
    MARKDOWN_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")


def register_fonts() -> tuple[str, str]:
    regular_path = Path("C:/Windows/Fonts/arial.ttf")
    bold_path = Path("C:/Windows/Fonts/arialbd.ttf")
    if regular_path.exists() and bold_path.exists():
        pdfmetrics.registerFont(TTFont("BacklogRegular", regular_path))
        pdfmetrics.registerFont(TTFont("BacklogBold", bold_path))
        return "BacklogRegular", "BacklogBold"
    return "Helvetica", "Helvetica-Bold"


def write_pdf(stories: list[dict[str, object]]) -> None:
    PDF_PATH.parent.mkdir(parents=True, exist_ok=True)
    regular_font, bold_font = register_fonts()
    total = sum(int(story["points"]) for story in stories)
    page_width, _ = landscape(A4)
    margin = 1.35 * cm
    available_width = page_width - 2 * margin

    document = SimpleDocTemplate(
        str(PDF_PATH),
        pagesize=landscape(A4),
        leftMargin=margin,
        rightMargin=margin,
        topMargin=1.15 * cm,
        bottomMargin=1.15 * cm,
        title="Backlog - User Stories",
        author="ChargeTrackr",
    )

    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        "BacklogTitle",
        parent=styles["Title"],
        fontName=bold_font,
        fontSize=22,
        leading=26,
        textColor=NAVY,
        alignment=TA_CENTER,
        spaceAfter=5,
    )
    summary_style = ParagraphStyle(
        "BacklogSummary",
        parent=styles["Normal"],
        fontName=regular_font,
        fontSize=10.5,
        leading=13,
        textColor=colors.HexColor("#505050"),
        alignment=TA_LEFT,
    )
    body_style = ParagraphStyle(
        "BacklogBody",
        parent=styles["Normal"],
        fontName=regular_font,
        fontSize=8.5,
        leading=10.4,
        textColor=colors.black,
    )
    id_style = ParagraphStyle(
        "BacklogId",
        parent=body_style,
        fontName=bold_font,
        alignment=TA_CENTER,
    )
    centered_style = ParagraphStyle(
        "BacklogCentered",
        parent=body_style,
        alignment=TA_CENTER,
    )
    header_style = ParagraphStyle(
        "BacklogHeader",
        parent=centered_style,
        fontName=bold_font,
        fontSize=10.5,
        leading=12.5,
        textColor=colors.white,
    )

    header = [
        Paragraph("ID", header_style),
        Paragraph("User Story", header_style),
        Paragraph("Priorité", header_style),
        Paragraph("Points", header_style),
    ]
    rows: list[list[Paragraph]] = [header]
    for story in stories:
        priority = str(story["priorite"])
        priority_style = ParagraphStyle(
            f"Priority{priority}",
            parent=centered_style,
            fontName=bold_font,
            textColor=PRIORITY_COLORS[priority],
        )
        rows.append(
            [
                Paragraph(str(story["id"]), id_style),
                Paragraph(str(story["user_story"]), body_style),
                Paragraph(priority, priority_style),
                Paragraph(str(story["points"]), centered_style),
            ]
        )

    column_widths = [1.7 * cm, available_width - 6.1 * cm, 2.6 * cm, 1.8 * cm]
    table = Table(rows, colWidths=column_widths, repeatRows=1, hAlign="CENTER")
    table_style = TableStyle(
        [
            ("BACKGROUND", (0, 0), (-1, 0), NAVY),
            ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
            ("FONTNAME", (0, 0), (-1, 0), bold_font),
            ("FONTSIZE", (0, 0), (-1, 0), 10.5),
            ("ALIGN", (0, 0), (-1, 0), "CENTER"),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 6),
            ("RIGHTPADDING", (0, 0), (-1, -1), 6),
            ("TOPPADDING", (0, 0), (-1, 0), 7),
            ("BOTTOMPADDING", (0, 0), (-1, 0), 7),
            ("TOPPADDING", (0, 1), (-1, -1), 5),
            ("BOTTOMPADDING", (0, 1), (-1, -1), 5),
            ("GRID", (0, 0), (-1, -1), 0.45, GRID),
        ]
    )
    for row_index in range(2, len(rows), 2):
        table_style.add("BACKGROUND", (0, row_index), (-1, row_index), ZEBRA)
    table.setStyle(table_style)

    elements = [
        Paragraph("Backlog Produit - User Stories", title_style),
        Paragraph(f"{len(stories)} user stories · Total : {total} points", summary_style),
        Spacer(1, 0.5 * cm),
        table,
    ]
    document.build(elements)


def main() -> None:
    stories = load_stories()
    write_markdown(stories)
    write_pdf(stories)
    print(f"Generated {len(stories)} stories and {sum(int(story['points']) for story in stories)} points.")
    print(MARKDOWN_PATH)
    print(PDF_PATH)


if __name__ == "__main__":
    main()
