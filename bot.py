import re
import random
from io import BytesIO
from cryptography.fernet import Fernet
import os
import logging
import asyncio
import time
from decouple import config
from telegram import (
    KeyboardButton,
    ReplyKeyboardMarkup,
    Update,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    ReplyKeyboardRemove
)
from telegram.constants import ParseMode, ChatAction
from telegram.ext import (
    CallbackContext,
    CommandHandler,
    MessageHandler,
    filters,  # Updated filters
    Application,  # Replaces Updater
    CallbackQueryHandler,
    ConversationHandler
)
from datetime import date
from portal import PortalClient


logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


wcu = 'WCU'

KEY = config('SECRET_KEY').encode()

LOGED_BUTTONS: list = [
    [KeyboardButton("Grade Report")],
    [KeyboardButton("Registered Courses")],
    [KeyboardButton("Placed Department")],
    [KeyboardButton("View Profile")],

]
AGREE, DISAGREE, CAMPUS, STUDENT_ID = range(4)
GRADE_REPORT = range(4, 8)
MATH_QUESTION, ACCOUNT_DELETED, ACCOUNT_NOT_DELETED = range(8, 11)
PLACED_DEPARTMENT_PASSWORD = 11
AUTH_USERNAME, AUTH_PASSWORD = 12, 13

SESSION_TTL_SECONDS = 1800
SESSION_CACHE = {}
BOT_TEMP_MESSAGE_TTL = 30


def encrypt_data(data: str, key: bytes) -> bytes:
    """
    Encrypts the input data using Fernet symmetric encryption.

    Args:
        data (str): The data to be encrypted as a string.
        key (bytes): The encryption key as bytes.

    Returns:
        bytes: The encrypted data as bytes.
    """
    fernet = Fernet(key)
    encrypted_data = fernet.encrypt(data.encode())
    return encrypted_data


def decrypt_data(encrypted_data: bytes, key: bytes) -> str:
    """
    Decrypts the encrypted data using the Fernet symmetric encryption key.

    Args:
        encrypted_data (bytes): The data to be decrypted as bytes.
        key (bytes): The encryption key as bytes.

    Returns:
        str: The decrypted data as a string.
    """
    fernet = Fernet(key)
    decrypted_data = fernet.decrypt(encrypted_data).decode()
    return decrypted_data


def is_user_id_valid(user_id: str) -> bool:
    """
    Checks if a user ID is valid based on a specific pattern.

    Args:
        user_id (str): The user ID to be validated.

    Returns:
        bool: True if the user ID is valid, False otherwise.
    """
    user_id: str = user_id.upper()
    pattern: str = r'^WCU\d+$'
    if re.match(pattern, user_id):
        return True
    else:
        return False


def generate_math_question() -> tuple:
    """
    Generates a random math question with two random numbers and an arithmetic operation.

    Returns:
        tuple: A tuple containing the math question (str) and the correct answer (int).
    """
    a = random.randint(1, 10)
    b = random.randint(1, 10)
    operation = random.choice(["+", "-", "*", "/"])
    if operation == "+": result = a + b
    elif operation == "-": result = a - b
    elif operation == "*": result = a * b
    elif operation == "/": result = a // b
    return f"What is {a} {operation} {b}?", result


def _is_course_code(value: str) -> bool:
    compact = value.replace(" ", "")
    return bool(re.fullmatch(r"[A-Za-z]{2,}\d{3,4}", compact))


def _is_grade_value(value: str) -> bool:
    normalized = value.strip().upper()
    return normalized in {
        "A+", "A", "A-",
        "B+", "B", "B-",
        "C+", "C", "C-",
        "D+", "D", "D-",
        "F", "PASS", "FAIL",
    }


def format_semester_text(raw_semester: str) -> str:
    """Convert raw scraped semester text into a cleaner Telegram-friendly layout."""
    lines = [line.strip() for line in raw_semester.splitlines() if line.strip()]
    if not lines:
        return "No semester data found."

    joined = " ".join(lines)

    academic_match = re.search(r"(Academic Year:[^A]+(?:Semester:[^\n]+)?)", joined, re.IGNORECASE)
    sgp_match = re.search(r"SGP\s*:\s*([\d.]+)", joined, re.IGNORECASE)
    sgpa_match = re.search(r"SGPA\s*:\s*([\d.]+)", joined, re.IGNORECASE)
    cgp_match = re.search(r"CGP\s*:\s*([\d.]+)", joined, re.IGNORECASE)
    cgpa_match = re.search(r"CGPA\s*:\s*([\d.]+)", joined, re.IGNORECASE)
    status_match = re.search(r"Academic Status\s*:\s*([A-Za-z ]+)", joined, re.IGNORECASE)

    courses = []
    i = 0
    while i < len(lines):
        if i + 1 < len(lines) and _is_course_code(lines[i + 1]):
            course_name = lines[i]
            course_code = lines[i + 1].replace(" ", "").upper()
            course_grade = "-"
            if i + 2 < len(lines) and _is_grade_value(lines[i + 2]):
                course_grade = lines[i + 2].upper()
                i += 3
            else:
                i += 2
            courses.append((course_name, course_code, course_grade))
            continue
        i += 1

    output = []
    output.append("📚 Semester Result")
    if academic_match:
        output.append(f"\n🗓 {academic_match.group(1).strip()}")

    if courses:
        output.append("\n📖 Courses")
        for idx, (name, code, grade) in enumerate(courses, 1):
            output.append(f"{idx}. {name}")
            output.append(f"   • Code: {code}")
            output.append(f"   • Grade: {grade}")
    else:
        output.append("\nNo course rows parsed from this page.")

    stats = []
    if sgp_match:
        stats.append(f"SGP: {sgp_match.group(1)}")
    if sgpa_match:
        stats.append(f"SGPA: {sgpa_match.group(1)}")
    if cgp_match:
        stats.append(f"CGP: {cgp_match.group(1)}")
    if cgpa_match:
        stats.append(f"CGPA: {cgpa_match.group(1)}")
    if status_match:
        stats.append(f"Status: {status_match.group(1).strip()}")

    if stats:
        output.append("\n📊 Summary")
        output.extend([f"• {item}" for item in stats])

    return "\n".join(output)


def main_menu_markup() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("📊 Grade Report", callback_data="menu_grade")],
        [InlineKeyboardButton("📚 Registered Courses", callback_data="menu_courses")],
        [InlineKeyboardButton("🏫 Placed Department", callback_data="menu_placement")],
        [InlineKeyboardButton("👤 View Profile", callback_data="menu_profile")],
        [InlineKeyboardButton("👨‍💻 Developer", callback_data="menu_developer")],
    ])


async def hide_sensitive_user_message(update: Update, context: CallbackContext) -> None:
    """Delete sensitive user inputs from chat after reading them."""
    message = update.effective_message
    if not message or not message.text:
        return
    if message.text.strip().startswith("/start"):
        return
    try:
        await context.bot.delete_message(chat_id=message.chat_id, message_id=message.message_id)
    except Exception:
        # Bot may not have rights in some chats; fail silently.
        pass


async def delete_message_later(context: CallbackContext, chat_id: int, message_id: int, delay_seconds: int) -> None:
    """Delete a bot message after delay to reduce chat history traces."""
    try:
        await asyncio.sleep(delay_seconds)
        await context.bot.delete_message(chat_id=chat_id, message_id=message_id)
    except Exception:
        pass


def track_temp_bot_message(context: CallbackContext, message_id: int) -> None:
    context.user_data.setdefault("temp_bot_message_ids", []).append(message_id)


async def clear_tracked_temp_bot_messages(update: Update, context: CallbackContext) -> None:
    ids = context.user_data.pop("temp_bot_message_ids", [])
    for mid in ids:
        try:
            await context.bot.delete_message(chat_id=update.effective_chat.id, message_id=mid)
        except Exception:
            pass


async def send_private_bot_message(
    update: Update,
    context: CallbackContext,
    text: str,
    ttl_seconds: int = BOT_TEMP_MESSAGE_TTL,
    reply_markup=None
):
    """Send a bot message and schedule deletion for privacy."""
    msg = await update.effective_message.reply_text(text, reply_markup=reply_markup)
    track_temp_bot_message(context, msg.message_id)
    asyncio.create_task(
        delete_message_later(context, update.effective_chat.id, msg.message_id, ttl_seconds)
    )
    return msg


async def render_panel(update: Update, context: CallbackContext, text: str, reply_markup=None) -> None:
    """Render a single bot panel message by editing in-place when possible."""
    chat_id = update.effective_chat.id
    query = update.callback_query
    panel_id = context.user_data.get("panel_message_id")

    if query and query.message:
        try:
            await query.edit_message_text(text=text, reply_markup=reply_markup)
            context.user_data["panel_message_id"] = query.message.message_id
            return
        except Exception:
            pass

    if panel_id:
        try:
            await context.bot.edit_message_text(
                chat_id=chat_id,
                message_id=panel_id,
                text=text,
                reply_markup=reply_markup,
            )
            return
        except Exception:
            pass

    msg = await update.effective_message.reply_text(text=text, reply_markup=reply_markup)
    context.user_data["panel_message_id"] = msg.message_id


async def clear_active_profile_message(update: Update, context: CallbackContext) -> None:
    """Remove previously shown profile photo message to avoid stale UI blocks."""
    profile_message_id = context.user_data.pop("active_profile_message_id", None)
    if not profile_message_id:
        return
    try:
        await context.bot.delete_message(
            chat_id=update.effective_chat.id,
            message_id=profile_message_id
        )
    except Exception:
        pass


def get_active_session_client(tg_id: int):
    session = SESSION_CACHE.get(tg_id)
    if not session:
        return None
    if session["expires_at"] < time.time():
        SESSION_CACHE.pop(tg_id, None)
        return None
    # Sliding session: active usage extends TTL.
    session["expires_at"] = time.time() + SESSION_TTL_SECONDS
    return session["client"]


def store_session_client(tg_id: int, client: PortalClient) -> None:
    SESSION_CACHE[tg_id] = {
        "client": client,
        "expires_at": time.time() + SESSION_TTL_SECONDS,
    }


def extract_academic_year(raw_semester: str) -> str:
    """Extract academic year label like 2024/25 from raw semester text."""
    match = re.search(r"Academic Year\s*:\s*(\d{4}\s*/\s*\d{2,4})", raw_semester, re.IGNORECASE)
    if match:
        return match.group(1).replace(" ", "")
    return "Other"


def extract_semester_label(raw_semester: str) -> str:
    match = re.search(r"Semester\s*:\s*([A-Za-z0-9]+)", raw_semester, re.IGNORECASE)
    if not match:
        return "Semester"
    value = match.group(1).strip()
    normalized = value.lower()
    mapping = {
        "one": "Semester 1",
        "two": "Semester 2",
        "i": "Semester 1",
        "ii": "Semester 2",
        "1": "Semester 1",
        "2": "Semester 2",
    }
    return mapping.get(normalized, f"Semester {value}")


def format_registered_courses_text(raw_semester: str) -> str:
    lines = [line.strip() for line in raw_semester.splitlines() if line.strip()]
    year = extract_academic_year(raw_semester)
    semester = extract_semester_label(raw_semester)
    courses = []
    i = 0
    while i < len(lines):
        if i + 1 < len(lines) and _is_course_code(lines[i + 1]):
            name = lines[i]
            code = lines[i + 1].replace(" ", "").upper()
            courses.append((name, code))
            i += 2
            continue
        i += 1

    output = [f"📚 Registered Courses\n\n🗓 Academic Year: {year}\n🧾 {semester}"]
    if not courses:
        output.append("\nNo registered courses found for this semester.")
        return "\n".join(output)
    output.append("\n📖 Course List")
    for idx, (name, code) in enumerate(courses, 1):
        output.append(f"{idx}. {name}")
        output.append(f"   • Code: {code}")
    return "\n".join(output)


def clamp_message_length(text: str, limit: int = 3900) -> str:
    """Keep message under Telegram limits to avoid edit/send 400 errors."""
    if len(text) <= limit:
        return text
    return text[: limit - 30] + "\n\n...truncated for Telegram limit."


async def show_main_menu(update: Update, context: CallbackContext) -> None:
    await render_panel(
        update,
        context,
        "Choose an option:",
        reply_markup=main_menu_markup()
    )


async def execute_action_with_client(update: Update, context: CallbackContext, action: str, client: PortalClient) -> None:
    await clear_active_profile_message(update, context)

    if action == "developer":
        await render_panel(
            update,
            context,
            text=(
                "👨‍💻 Developer Contact\n\n"
                "Name: Dagmawi Lencho\n"
                "Website: https://dagmawilencho.dev\n"
                "Telegram: @Duyyee"
            ),
            reply_markup=InlineKeyboardMarkup(
                [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
            )
        )
        return

    if action == "profile":
        profile = client.get_profile()
        if isinstance(profile, tuple):
            image_source, caption = profile
            sent_photo = False
            # Hide the menu panel so only profile content stays visible.
            panel_id = context.user_data.pop("panel_message_id", None)
            if panel_id:
                try:
                    await context.bot.delete_message(
                        chat_id=update.effective_chat.id,
                        message_id=panel_id
                    )
                except Exception:
                    pass
            back_markup = InlineKeyboardMarkup(
                [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
            )
            try:
                # First try direct URL send (works for public images).
                await context.bot.send_photo(
                    chat_id=update.effective_chat.id,
                    photo=image_source,
                    caption=caption,
                    reply_markup=back_markup
                )
                sent_msg = await context.bot.send_photo(
                    chat_id=update.effective_chat.id,
                    photo=image_source,
                    caption=caption,
                    reply_markup=back_markup
                )
                context.user_data["active_profile_message_id"] = sent_msg.message_id
                sent_photo = True
            except Exception:
                # If portal image requires authenticated session/cookies, fetch it with the logged-in client.
                try:
                    resp = client.session.get(image_source, verify=False, timeout=20)
                    if resp.status_code == 200 and resp.content:
                        photo_bytes = BytesIO(resp.content)
                        photo_bytes.name = "profile.jpg"
                        sent_msg = await context.bot.send_photo(
                            chat_id=update.effective_chat.id,
                            photo=photo_bytes,
                            caption=caption,
                            reply_markup=back_markup
                        )
                        context.user_data["active_profile_message_id"] = sent_msg.message_id
                        sent_photo = True
                except Exception:
                    sent_photo = False

            if not sent_photo:
                await render_panel(
                    update,
                    context,
                    text=f"👤 Profile\n\n{caption}\n\n(Profile photo unavailable right now.)",
                    reply_markup=InlineKeyboardMarkup(
                        [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
                    )
                )
                return
            # Keep panel unchanged; back is attached directly under the photo.
        else:
            await render_panel(
                update,
                context,
                text=str(profile),
                reply_markup=InlineKeyboardMarkup(
                    [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
                )
            )
        return

    if action == "placement":
        result = client.get_placed_department()
        await render_panel(
            update,
            context,
            text=result,
            reply_markup=InlineKeyboardMarkup(
                [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
            )
        )
        return

    if action in {"grade", "courses"}:
        grades = client.get_grades()
        if isinstance(grades, str):
            await render_panel(
                update,
                context,
                text=grades,
                reply_markup=InlineKeyboardMarkup(
                    [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
                )
            )
            return

        if not grades:
            await render_panel(
                update,
                context,
                text="No grade information available.",
                reply_markup=InlineKeyboardMarkup(
                    [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
                )
            )
            return

        grouped_entries = {}
        for raw_semester in grades:
            year = extract_academic_year(raw_semester)
            grouped_entries.setdefault(year, []).append({
                "semester_label": extract_semester_label(raw_semester),
                "raw": raw_semester,
            })

        available_years = sorted(grouped_entries.keys(), reverse=True)
        context.user_data["available_years"] = available_years
        context.user_data["entries_by_year"] = grouped_entries
        context.user_data["selected_year"] = None
        context.user_data["selected_entries"] = []
        await send_year_menu(update, context)
        return

    await render_panel(
        update,
        context,
        text="Unknown action selected.",
        reply_markup=main_menu_markup()
    )


async def begin_action(update: Update, context: CallbackContext) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        data = update.callback_query.data
        mapping = {
            "menu_grade": "grade",
            "menu_courses": "courses",
            "menu_placement": "placement",
            "menu_profile": "profile",
            "menu_developer": "developer",
        }
        action = mapping.get(data)
    else:
        text = (update.effective_message.text or "").strip().lower()
        mapping = {
            "grade report": "grade",
            "registered courses": "courses",
            "placed department": "placement",
            "view profile": "profile",
            "developer": "developer",
        }
        action = mapping.get(text)

    if not action:
        await show_main_menu(update, context)
        return ConversationHandler.END

    # Developer contact is public and should not require login.
    if action == "developer":
        await render_panel(
            update,
            context,
            text=(
                "👨‍💻 Developer Contact\n\n"
                "Name: Dagmawi Lencho\n"
                "Website: https://dagmawilencho.dev\n"
                "Telegram: @Duyyee"
            ),
            reply_markup=InlineKeyboardMarkup(
                [[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]]
            )
        )
        return ConversationHandler.END

    context.user_data["pending_action"] = action
    client = get_active_session_client(update.effective_user.id)
    if client:
        await execute_action_with_client(update, context, action, client)
        return ConversationHandler.END

    keyboard = InlineKeyboardMarkup([[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]])
    await render_panel(
        update,
        context,
        "Enter your portal username (e.g., WCU174538):",
        reply_markup=keyboard
    )
    return AUTH_USERNAME


async def handle_auth_username(update: Update, context: CallbackContext) -> int:
    username = (update.effective_message.text or "").strip().upper()
    await hide_sensitive_user_message(update, context)
    if not is_user_id_valid(username):
        keyboard = InlineKeyboardMarkup([[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]])
        await render_panel(
            update,
            context,
            "Invalid username format. Use format like WCU174538.",
            reply_markup=keyboard
        )
        return AUTH_USERNAME

    context.user_data["auth_username"] = username
    keyboard = InlineKeyboardMarkup([[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]])
    await render_panel(
        update,
        context,
        "Enter your portal password:",
        reply_markup=keyboard
    )
    return AUTH_PASSWORD


async def handle_auth_password(update: Update, context: CallbackContext) -> int:
    password = (update.effective_message.text or "").strip()
    await hide_sensitive_user_message(update, context)
    username = context.user_data.get("auth_username")
    action = context.user_data.get("pending_action", "grade")
    if not username:
        await show_main_menu(update, context)
        return ConversationHandler.END

    await render_panel(
        update,
        context,
        "Logging in...",
        reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]])
    )
    client = PortalClient(campus=wcu, student_id=username, password=password)
    login_error = client.login()
    if login_error:
        keyboard = InlineKeyboardMarkup([[InlineKeyboardButton("⬅️ Back", callback_data="back_main")]])
        await render_panel(
            update,
            context,
            f"{login_error}\nTry password again:",
            reply_markup=keyboard
        )
        return AUTH_PASSWORD

    store_session_client(update.effective_user.id, client)
    context.user_data.pop("auth_username", None)
    await render_panel(
        update,
        context,
        "✅ Login successful. Session active for 30 minutes."
    )
    if action == "menu_home":
        await show_main_menu(update, context)
        return ConversationHandler.END
    await execute_action_with_client(update, context, action, client)
    return ConversationHandler.END


async def back_to_main(update: Update, context: CallbackContext) -> None:
    query = update.callback_query
    await query.answer()
    # If back is clicked under a profile photo, remove that photo message first.
    try:
        if query.message and getattr(query.message, "photo", None):
            await query.message.delete()
    except Exception:
        pass
    context.user_data.pop("auth_username", None)
    context.user_data.pop("pending_action", None)
    # Back should always return to menu. Login is requested only when protected actions are tapped.
    await show_main_menu(update, context)


async def back_to_years(update: Update, context: CallbackContext) -> None:
    query = update.callback_query
    await query.answer()
    context.user_data["selected_year"] = None
    context.user_data["selected_entries"] = []
    await send_year_menu(update, context)


async def send_year_menu(update: Update, context: CallbackContext) -> None:
    years = context.user_data.get("available_years", [])
    if not years:
        await render_panel(update, context, "No academic years found.", reply_markup=main_menu_markup())
        return

    buttons = []
    for idx, year in enumerate(years):
        buttons.append([InlineKeyboardButton(f"📅 {year}", callback_data=f"year_{idx}")])
    buttons.append([InlineKeyboardButton("⬅️ Back", callback_data="back_main")])

    action = context.user_data.get("pending_action", "grade")
    title = "Select academic year for grade report:" if action == "grade" else "Select academic year for registered courses:"

    await render_panel(
        update,
        context,
        title,
        reply_markup=InlineKeyboardMarkup(buttons)
    )


async def send_semester_menu(update: Update, context: CallbackContext) -> None:
    entries = context.user_data.get("selected_entries", [])
    if not entries:
        await render_panel(update, context, "No semester data found.", reply_markup=main_menu_markup())
        return

    buttons = []
    for idx, entry in enumerate(entries):
        buttons.append([InlineKeyboardButton(entry["semester_label"], callback_data=f"sem_{idx}")])
    buttons.append([InlineKeyboardButton("⬅️ Back", callback_data="back_years")])
    buttons.append([InlineKeyboardButton("🏠 Menu", callback_data="back_main")])

    action = context.user_data.get("pending_action", "grade")
    year = context.user_data.get("selected_year", "")
    title = f"Select semester for grade report ({year}):" if action == "grade" else f"Select semester for registered courses ({year}):"
    await render_panel(update, context, title, reply_markup=InlineKeyboardMarkup(buttons))



async def math_question(update: Update, context: CallbackContext) -> int:
    """
    Generates a math question and sends it to the user with answer options.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The next state of the conversation.
    """
    if update.callback_query:
        await update.callback_query.answer()
    question, correct_answer = generate_math_question()
    answers = [correct_answer, correct_answer + 1, correct_answer - 1]
    random.shuffle(answers)

    keyboard = [
        [InlineKeyboardButton(str(answers[0]), callback_data=f"answer_{answers[0]}")],
        [InlineKeyboardButton(str(answers[1]), callback_data=f"answer_{answers[1]}")],
        [InlineKeyboardButton(str(answers[2]), callback_data=f"answer_{answers[2]}")]
    ]
    await update.effective_message.reply_text(question, reply_markup=InlineKeyboardMarkup(keyboard))
    context.user_data['correct_answer'] = correct_answer
    return ACCOUNT_DELETED


async def handle_math_answer(update: Update, context: CallbackContext) -> int:
    """
    Handles the user's answer to a math question.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The next state of the conversation.
    """

    query = update.callback_query
    await query.answer()
    user_answer = int(query.data.split('_')[1])
    correct_answer = context.user_data.get('correct_answer')

    if user_answer == correct_answer:
        delete_from_table(query.from_user.id)
        await query.edit_message_text("✅ Account deleted successfully!")
        return ConversationHandler.END

    # Generate new question
    question, new_answer = generate_math_question()
    answers = [new_answer, new_answer + 1, new_answer - 1]
    random.shuffle(answers)
    
    keyboard = [
        [InlineKeyboardButton(str(answers[0]), callback_data=f"answer_{answers[0]}")],
        [InlineKeyboardButton(str(answers[1]), callback_data=f"answer_{answers[1]}")],
        [InlineKeyboardButton(str(answers[2]), callback_data=f"answer_{answers[2]}")]
    ]
    await query.edit_message_text(
        text="❌ Incorrect answer. Try again:\n\n" + question,
        reply_markup=InlineKeyboardMarkup(keyboard)
    )
    context.user_data['correct_answer'] = new_answer
    return ACCOUNT_DELETED



async def ask_for_password(update: Update, context: CallbackContext) -> int:
    """
    Initiates the process of asking the user to enter a password to view the grade report.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The next state of the conversation.
    """
    if update.callback_query:
        await update.callback_query.answer()
    msg = await update.effective_message.reply_text(
        "🔒 Please enter your password to view the grade report:",
        reply_markup=ReplyKeyboardRemove()
    )
    context.user_data['password_msg_id'] = msg.message_id
    return GRADE_REPORT


async def ask_for_placement_password(update: Update, context: CallbackContext) -> int:
    """Ask for password before checking placed department."""
    if update.callback_query:
        await update.callback_query.answer()
    await update.effective_message.reply_text(
        "🔒 Please enter your password to check your placed department:",
        reply_markup=ReplyKeyboardRemove()
    )
    return PLACED_DEPARTMENT_PASSWORD


async def get_placed_department(update: Update, context: CallbackContext) -> int:
    """Fetch placed department from the portal for the registered user."""
    try:
        password = update.effective_message.text
        await hide_sensitive_user_message(update, context)
        tg_id = update.effective_user.id
        registered = search_table_by_tg_id(tg_id)

        if not registered:
            await update.effective_message.reply_text(
                "Placed department check is not available for unregistered users.\n/start here",
                reply_markup=ReplyKeyboardRemove()
            )
            return ConversationHandler.END

        _, reg_id, _, reg_campus, _ = registered
        working_on_it_msg = await update.effective_message.reply_text("Checking your placed department...")

        client = PortalClient(
            campus=decrypt_data(reg_campus, KEY),
            student_id=decrypt_data(reg_id, KEY),
            password=password
        )
        login_error = client.login()
        if login_error:
            await working_on_it_msg.delete()
            await update.effective_message.reply_text(login_error)
            return ConversationHandler.END

        result = client.get_placed_department()
        await working_on_it_msg.delete()
        await update.effective_message.reply_text(result, reply_markup=main_menu_markup())
        return ConversationHandler.END

    except Exception as e:
        logging.error(f"Error in get_placed_department: {e}")
        await update.effective_message.reply_text(
            "An error occurred while checking your placed department. Please try again later."
        )
        return ConversationHandler.END

async def get_password(update: Update, context: CallbackContext) -> int:
    """
    Handles password input and initiates grade report retrieval with pagination.
    For graduates, it congratulates them and skips fetching grades.
    """
    try:
        password = update.message.text
        await hide_sensitive_user_message(update, context)
        tg_id = update.message.from_user.id
        registered = search_table_by_tg_id(tg_id)

        if not registered:
            await update.message.reply_text(
                "Viewing grade report is not available for unregistered users.\n/start here",
                reply_markup=ReplyKeyboardRemove()
            )
            return ConversationHandler.END

        # Existing registration check and data retrieval
        reg_data = search_table_by_tg_id(tg_id)
        reg_tg_id, reg_id, reg_name, reg_campus, reg_date = reg_data

        working_on_it_msg = await update.message.reply_text("Working on it...")
        await context.bot.send_chat_action(update.effective_chat.id, ChatAction.TYPING)

        campus = decrypt_data(reg_campus, KEY)
        student_id = decrypt_data(reg_id, KEY)
        client = PortalClient(campus=campus, student_id=student_id, password=password)

        login_error = client.login()
        if login_error:
            await working_on_it_msg.delete()
            await update.message.reply_text(login_error)
            return ConversationHandler.END

        # Profile handling
        profile = client.get_profile()

        # Check if the user is a graduate
        if profile == "It seems you are a graduate, so I am skipping your profile and showing your grade report below.":
            await update.message.reply_text(
                "🎓 Congratulations! You are a graduate. 🎓\n\n"
                "As a graduate, I can no longer provide your grade report. "
                "This feature is only available for active students.\n\n"
                "Thank you for using WCU RESULT! 🎉"
            )
            return ConversationHandler.END  # End the conversation for graduates

        # If the user is not a graduate, proceed with fetching and displaying the profile
        elif isinstance(profile, tuple):
            await context.bot.send_photo(
                update.effective_chat.id,
                photo=profile[0],
                caption=profile[1]
            )

        # Get grades from the same authenticated session.
        grades = client.get_grades()
        if isinstance(grades, str):
            await working_on_it_msg.delete()
            await update.message.reply_text(grades)
            return ConversationHandler.END

        # Group semesters by academic year and format each one.
        if grades:
            grouped_raw = {}
            for raw_semester in grades:
                year = extract_academic_year(raw_semester)
                grouped_raw.setdefault(year, []).append(raw_semester)
            available_years = sorted(grouped_raw.keys(), reverse=True)
            semesters_by_year = {
                year: [format_semester_text(raw) for raw in grouped_raw[year]]
                for year in available_years
            }
        else:
            await working_on_it_msg.delete()
            await update.message.reply_text("No grade information available.")
            return ConversationHandler.END

        context.user_data['available_years'] = available_years
        context.user_data['semesters_by_year'] = semesters_by_year
        context.user_data.pop('semester_message_id', None)
        context.user_data['current_page'] = 0

        # If only one year exists, open it directly. Otherwise show dynamic year menu.
        await working_on_it_msg.delete()
        if len(available_years) == 1:
            selected_year = available_years[0]
            context.user_data['selected_year'] = selected_year
            context.user_data['semesters'] = semesters_by_year[selected_year]
            context.user_data['semesters'][-1] += "\n\n🤖 WCU RESULT"
            await send_semester(update, context)
        else:
            await send_year_menu(update, context)
        return ConversationHandler.END

    except Exception as e:
        logging.error(f"Error in get_password: {e}")
        await update.message.reply_text(
            "An error occurred while processing your request. Please try again later."
        )
        return ConversationHandler.END


async def send_semester(update: Update, context: CallbackContext) -> None:
    """Send a semester with pagination buttons"""
    semesters = context.user_data.get('semesters', [])
    current_page = context.user_data.get('current_page', 0)
    total_pages = len(semesters)

    if not semesters:
        await render_panel(update, context, "No grade information available", reply_markup=main_menu_markup())
        return

    # Create pagination buttons
    buttons = []
    if total_pages > 1:
        if current_page > 0:
            buttons.append(InlineKeyboardButton("⬅️ Previous", callback_data="prev"))
        if current_page < total_pages - 1:
            buttons.append(InlineKeyboardButton("Next ➡️", callback_data="next"))
    if context.user_data.get("available_years"):
        buttons.append(InlineKeyboardButton("📅 Years", callback_data="back_years"))
    buttons.append(InlineKeyboardButton("🏠 Menu", callback_data="back_main"))

    # Add footer with page numbers
    footer = f"\n\n📄 Page {current_page + 1} of {total_pages}"
    message_text = clamp_message_length(semesters[current_page] + footer)

    await render_panel(
        update,
        context,
        message_text,
        reply_markup=InlineKeyboardMarkup([buttons]) if buttons else None
    )


async def handle_year_selection(update: Update, context: CallbackContext) -> None:
    """Handle academic year picker callbacks."""
    query = update.callback_query
    await query.answer()

    years = context.user_data.get("available_years", [])
    entries_by_year = context.user_data.get("entries_by_year", {})
    try:
        year_index = int(query.data.split("_")[1])
        selected_year = years[year_index]
    except Exception:
        await render_panel(update, context, "Invalid year selection. Try again.", reply_markup=main_menu_markup())
        return

    entries = entries_by_year.get(selected_year, [])
    if not entries:
        await render_panel(update, context, "No semester data found for this academic year.", reply_markup=main_menu_markup())
        return

    context.user_data['selected_year'] = selected_year
    context.user_data['selected_entries'] = entries
    await send_semester_menu(update, context)


async def handle_semester_selection(update: Update, context: CallbackContext) -> None:
    query = update.callback_query
    await query.answer()

    entries = context.user_data.get("selected_entries", [])
    try:
        sem_index = int(query.data.split("_")[1])
        entry = entries[sem_index]
    except Exception:
        await render_panel(update, context, "Invalid semester selection.", reply_markup=main_menu_markup())
        return

    action = context.user_data.get("pending_action", "grade")
    year = context.user_data.get("selected_year", "")
    semester_label = entry.get("semester_label", "Semester")
    raw = entry.get("raw", "")

    if action == "courses":
        body = format_registered_courses_text(raw)
    else:
        body = format_semester_text(raw)

    body = clamp_message_length(body + f"\n\n🗓 {year} • {semester_label}\n\n🤖 WCU RESULT")
    markup = InlineKeyboardMarkup([
        [InlineKeyboardButton("⬅️ Back", callback_data="back_years")],
        [InlineKeyboardButton("🏠 Menu", callback_data="back_main")],
    ])
    await render_panel(update, context, body, reply_markup=markup)

async def handle_page_navigation(update: Update, context: CallbackContext) -> None:
    """Handle pagination button presses"""
    query = update.callback_query
    await query.answer()

    current_page = context.user_data.get('current_page', 0)

    if query.data == "prev" and current_page > 0:
        context.user_data['current_page'] -= 1
    elif query.data == "next":
        context.user_data['current_page'] += 1

    await send_semester(update, context)

async def view_profile(update: Update, context: CallbackContext) -> int:
    """
    Displays the user's profile information.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The next state of the conversation.
    """
    if update.callback_query:
        await update.callback_query.answer()

    tg_id = update.effective_user.id
    registered = search_table_by_tg_id(tg_id)

    if registered:
        # Fetch user data
        reg_tg_id, reg_id, reg_name, reg_campus, reg_date = search_table_by_tg_id(tg_id)

        # Decrypt data
        portal_id = decrypt_data(reg_id, KEY)
        telegram_name = decrypt_data(reg_name, KEY)
        portal_name = decrypt_data(reg_campus, KEY)
        registration_date = decrypt_data(reg_date, KEY)

        # Build profile message with emojis and formatting
        profile_message = (
            "📄 **Your Profile Information**\n\n"
            f"🆔 **Telegram ID**: `{reg_tg_id}`\n"
            f"📋 **Portal ID**: `{portal_id}`\n"
            f"👤 **Telegram Name**: {telegram_name}\n"
            f"🏫 **Campus**: {portal_name}\n"
            f"📅 **Date of Registration**: {registration_date}\n\n"
            "You can use the buttons below to explore more features!"
        )

        # Send profile message
        await update.effective_message.reply_text(
            profile_message,
            parse_mode=ParseMode.MARKDOWN,
            reply_markup=main_menu_markup()
        )
    else:
        # User is not registered
        unregistered_message = (
            "❌ **Profile Unavailable**\n\n"
            "You are not registered with WCU RESULT.\n"
            "To register, use the /start command and follow the instructions.\n\n"
            "If you need help, use /help."
        )
        await update.effective_message.reply_text(
            unregistered_message,
            parse_mode=ParseMode.MARKDOWN,
            reply_markup=ReplyKeyboardRemove()
        )

    return ConversationHandler.END


async def start(update: Update, context: CallbackContext) -> int:
    """
    Handles the start of the conversation and user registration.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The next state of the conversation.
    """
    try:
        await context.bot.send_chat_action(
            chat_id=update.effective_chat.id, action=ChatAction.TYPING
        )
        first_name = update.effective_user.first_name or "Student"
        panel_message_id = context.user_data.get("panel_message_id")
        active_client = get_active_session_client(update.effective_user.id)
        context.user_data.clear()
        if panel_message_id:
            context.user_data["panel_message_id"] = panel_message_id
        if active_client:
            await render_panel(
                update,
                context,
                f"👋 Welcome back, {first_name}!\n\nSession is active.",
                reply_markup=main_menu_markup()
            )
            return ConversationHandler.END
        context.user_data["pending_action"] = "menu_home"
        await render_panel(
            update,
            context,
            f"👋 Welcome, {first_name}, to WCU RESULT!\n\n"
            "For privacy, login is temporary and expires after 30 minutes.\n\n"
            "Enter your portal username (e.g., WCU174538):"
        )
        return AUTH_USERNAME

    except Exception as e:
        logger.error(f"Error in start function: {e}")
        # Fallback for critical errors
        await update.effective_message.reply_text(
            "👋 Welcome to WCU RESULT!\n\n"
            "Something went wrong. Please try again or contact support."
        )
        return ConversationHandler.END


async def cancel(update: Update, context: CallbackContext) -> int:
    """
    Cancel the registration process and reset the conversation.

    This function handles the '/cancel' command, which allows the user to cancel
    the ongoing registration process and reset the conversation to its initial state.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The final state of the conversation, which is ConversationHandler.END.
    """
    # Clear user data to reset the registration process
    context.user_data.clear()

    # Send cancellation message with emojis and clear instructions
    cancellation_message = (
        "❌ **Registration Canceled**\n\n"
        "The registration process has been canceled.\n\n"
        "You can start over by typing /start or use /help for assistance."
    )

    await update.message.reply_text(
        cancellation_message,
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=ReplyKeyboardRemove()
    )
    return ConversationHandler.END


async def leave(update: Update, context: CallbackContext) -> int:
    """
    Cancel the account deletion process and reset the conversation.

    This function handles the '/leave' command, which allows the user to cancel
    the ongoing account deletion process and reset the conversation to its initial state.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The final state of the conversation, which is ConversationHandler.END.
    """
    # Clear user data to reset the account deletion process
    context.user_data.clear()

    # Send cancellation message with emojis and clear instructions
    cancellation_message = (
        "❌ **Account Deletion Canceled**\n\n"
        "The account deletion process has been canceled.\n\n"
        "You can start over by typing /start or use /help for assistance."
    )

    await update.message.reply_text(
        cancellation_message,
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=ReplyKeyboardRemove()
    )
    return ConversationHandler.END


async def registration(update: Update, context: CallbackContext) -> int:
    """Handles the registration process."""
    query = update.callback_query
    await query.answer()

    if query.data == "agree":
        # User agreed to terms
        context.user_data['campus'] = wcu
        await context.bot.send_message(
            chat_id=query.message.chat_id,
            text="🏫 Campus: WCU\n\n📝 Please enter your student ID in `WCU174538` format:",
            parse_mode=ParseMode.MARKDOWN
        )
        return STUDENT_ID
    elif query.data == "disagree":
        # User disagreed with terms
        disagreement_message = (
            "❌ **Registration Canceled**\n\n"
            "You must agree to the terms and conditions to use this bot.\n\n"
            "If you change your mind, you can start over by typing /start."
        )

        await context.bot.send_message(
            chat_id=query.message.chat_id,
            text=disagreement_message,
            parse_mode=ParseMode.MARKDOWN
        )
        return ConversationHandler.END
    else:
        return STUDENT_ID


async def choose_campus(update: Update, context: CallbackContext) -> int:
    """Handles the selection of a campus during registration."""
    query = update.callback_query
    await query.answer()
    context.user_data['campus'] = query.data

    await context.bot.send_message(
        chat_id=query.message.chat_id,
        text=f"🏫 Selected campus: {query.data}\n\n📝 Please enter your student ID in `WCU174538` format:",
        parse_mode=ParseMode.MARKDOWN
    )

    return STUDENT_ID


async def get_student_id(update: Update, context: CallbackContext) -> int:
    """
    Handles the user input of their student ID during registration.

    Args:
        update (telegram.Update): The incoming update from Telegram.
        context (telegram.ext.CallbackContext): The context for the conversation.

    Returns:
        int: The next state of the conversation.
    """
    student_id = update.message.text.upper()
    await hide_sensitive_user_message(update, context)
    tg_id = update.message.from_user.id
    username = update.message.from_user.first_name
    campus = context.user_data['campus']
    date_joined = date.today().strftime("%d/%m/%Y")

    try:
        if is_user_id_valid(student_id):
            # Perform registration and database insertion
            saved = insert_data((
                str(tg_id),
                encrypt_data(student_id, KEY),
                encrypt_data(username, KEY),
                encrypt_data(campus, KEY),
                encrypt_data(date_joined, KEY)
            ))
            if not saved:
                await update.message.reply_text(
                    "❌ Registration failed while saving your data. Please try /start again."
                )
                return ConversationHandler.END

            # Success message with emojis
            success_message = (
                f"✅ Registration successful!\n\n"
                f"👤 Name: {username}\n"
                f"🏫 Campus: {campus}\n"
                f"🆔 Student ID: ********\n\n"
                f"You can now use WCU RESULT. Choose an option below to get started!"
            )

            await update.message.reply_text(
                success_message,
                reply_markup=main_menu_markup()
            )
            return ConversationHandler.END

        else:
            # Invalid student ID format
            error_message = (
                "❌ Invalid student ID format.\n\n"
                "Please use the correct format: `WCU174538`\n\n"
                "For example: `WCU174538`\n\n"
                "If you want to cancel registration, use /cancel."
            )
            await update.message.reply_text(
                error_message,
                parse_mode=ParseMode.MARKDOWN,
                reply_markup=ReplyKeyboardRemove()
            )
            return STUDENT_ID

    except Exception as e:
        logger.error(f"Error in get_student_id: {e}")
        # Fallback for unexpected errors
        await update.message.reply_text(
            "❌ An error occurred during registration. Please try again later or contact support."
        )
        return ConversationHandler.END


async def filter_photos(update: Update, context: CallbackContext) -> None:
    """Detects photos from user and informs them that the bot cannot process photos."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"📸 Dear {user}, I currently don't support searching or processing photos. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_videos(update: Update, context: CallbackContext) -> None:
    """Detects videos from user and informs them that the bot cannot process videos."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"🎥 Dear {user}, I currently don't support searching or processing videos. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_contacts(update: Update, context: CallbackContext) -> None:
    """Detects contacts from user and informs them that the bot cannot process contacts."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"📇 Dear {user}, I currently don't support processing contacts. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_polls(update: Update, context: CallbackContext) -> None:
    """Detects polls from user and informs them that the bot cannot process polls."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"📊 Dear {user}, I currently don't support processing polls. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_captions(update: Update, context: CallbackContext) -> None:
    """Detects captions from user and informs them that the bot cannot process captions."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"📝 Dear {user}, I currently don't support processing captions. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_stickers(update: Update, context: CallbackContext) -> None:
    """Detects stickers from user and informs them that the bot cannot process stickers."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"🖼️ Dear {user}, I currently don't support processing stickers. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_animations(update: Update, context: CallbackContext) -> None:
    """Detects animations from user and informs them that the bot cannot process animations."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"🎞️ Dear {user}, I currently don't support processing animations. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_attachments(update: Update, context: CallbackContext) -> None:
    """Detects attachments from user and informs them that the bot cannot process attachments."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"📎 Dear {user}, I currently don't support processing attachments. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_audios(update: Update, context: CallbackContext) -> None:
    """Detects audios from user and informs them that the bot cannot process audios."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"🎵 Dear {user}, I currently don't support processing audios. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_dice(update: Update, context: CallbackContext) -> None:
    """Detects dice from user and informs them that the bot cannot process dice."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"🎲 Dear {user}, I currently don't support processing dice. "
        "Please use text-based commands instead!",
        quote=True
    )


async def filter_documents(update: Update, context: CallbackContext) -> None:
    """Detects documents from user and informs them that the bot cannot process documents."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"📄 Dear {user}, I currently don't support processing documents. "
        "Please use text-based commands instead!",
        quote=True
    )


async def policy(update: Update, context: CallbackContext) -> None:
    """Informs the user about how the bot handles user data."""
    user: str = update.message.from_user.first_name
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        f"🔒 **Privacy Policy**\n\n"
        f"Hello {user},\n\n"
        "At WCU RESULT, we take your privacy seriously. Here's how we handle your data:\n\n"
        "1. **No Data Storage**: We do not store your student ID, password, or any personal information.\n"
        "2. **Secure Communication**: All data is transmitted securely using encryption.\n"
        "3. **Transparency**: Your records are fetched from the official WCU portal.\n\n"
        "If you have any concerns, feel free to reach out to the bot developer.",
        reply_markup=ReplyKeyboardRemove(),
        parse_mode=ParseMode.MARKDOWN
    )


async def about(update: Update, context: CallbackContext) -> None:
    """Provides information about the bot."""
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        "🤖 **About WCU RESULT**\n\n"
        "WCU RESULT is designed to help students at Wachemo University access their "
        "academic information quickly and securely.\n\n"
        "Portal: https://portal.wcu.edu.et/",
        reply_markup=ReplyKeyboardRemove(),
        parse_mode=ParseMode.MARKDOWN
    )
    return


async def help(update: Update, context: CallbackContext) -> None:
    """Provides help information to the user."""
    await context.bot.send_chat_action(
        chat_id=update.effective_chat.id, action=ChatAction.TYPING
    )
    await update.message.reply_text(
        "🆘 **Help**\n\n"
        "Here are some commands you can use:\n\n"
        "- /start: Start the bot and register.\n"
        "- /help: Get help and instructions.\n"
        "- /policy: Learn about our privacy policy.\n"
        "- /about: Learn more about WCU RESULT.\n\n"
        "Portal: https://portal.wcu.edu.et/",
        reply_markup=ReplyKeyboardRemove(),
        parse_mode=ParseMode.MARKDOWN
    )
    return



def bot_tele() -> None:
    # Create application
    application = (
        Application.builder().token(config('TELEGRAM_BOT_TOKEN')).build()
    )

    

    
    auth_handler = ConversationHandler(
        entry_points=[
            CommandHandler('start', start),
            MessageHandler(filters.Regex("^(Grade Report|Registered Courses|Placed Department|View Profile|Developer)$"), begin_action),
            CallbackQueryHandler(begin_action, pattern="^menu_(grade|courses|placement|profile|developer)$"),
        ],
        states={
            AUTH_USERNAME: [
                MessageHandler(filters.TEXT & ~filters.COMMAND, handle_auth_username),
                CallbackQueryHandler(back_to_main, pattern="^back_main$"),
            ],
            AUTH_PASSWORD: [
                MessageHandler(filters.TEXT & ~filters.COMMAND, handle_auth_password),
                CallbackQueryHandler(back_to_main, pattern="^back_main$"),
            ],
        },
        fallbacks=[CommandHandler('start', start), CommandHandler('cancel', cancel)],
        allow_reentry=True,
        name="auth_actions",
    )
    application.add_handler(auth_handler)

    application.add_handler(CallbackQueryHandler(handle_year_selection, pattern="^year_\\d+$"))
    application.add_handler(CallbackQueryHandler(handle_semester_selection, pattern="^sem_\\d+$"))
    application.add_handler(CallbackQueryHandler(back_to_main, pattern="^back_main$"))
    application.add_handler(CallbackQueryHandler(back_to_years, pattern="^back_years$"))
    application.add_handler(CommandHandler('policy', policy))
    application.add_handler(CommandHandler('about', about))
    application.add_handler(CommandHandler('help', help))
    application.add_handler(MessageHandler(filters.PHOTO, filter_photos))
    application.add_handler(MessageHandler(filters.VIDEO, filter_videos))
    application.add_handler(MessageHandler(filters.CONTACT, filter_contacts))
    application.add_handler(MessageHandler(filters.POLL, filter_polls))
    application.add_handler(MessageHandler(filters.CAPTION, filter_captions))
    application.add_handler(MessageHandler(filters.Sticker.ALL, filter_stickers))
    application.add_handler(MessageHandler(filters.ANIMATION, filter_animations))
    application.add_handler(MessageHandler(filters.Document.ALL, filter_documents))
    application.add_handler(MessageHandler(filters.AUDIO, filter_audios))
    application.add_handler(MessageHandler(filters.Dice.ALL, filter_dice))
    application.add_handler(MessageHandler(filters.ATTACHMENT, filter_attachments))


    
    application.run_polling()


if __name__ == "__main__":
    bot_tele()
