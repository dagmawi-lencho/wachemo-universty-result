# portal.py
"""
A telegram client for safely communicating with WCU Portal.
"""

import requests
from bs4 import BeautifulSoup
from enum import Enum
from typing import Union, Tuple, List, Dict
import urllib3
import re
from urllib.parse import urljoin, urlparse

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

class Campus(Enum):
    """Enumeration of supported campus portal base URLs."""
    WCU = 'https://portal.wcu.edu.et/'

class PortalClient:
    """A client for interacting with the WCU Portal, maintaining a single session for efficiency."""
    
    def __init__(self, campus: str, student_id: str, password: str):
        """
        Initialize the client with campus, student credentials, and a requests session.
        
        Args:
            campus (str): The campus code ('WCU').
            student_id (str): The student's ID.
            password (str): The student's password.
        """
        self.campus = campus.upper()
        try:
            self.base_url = Campus[self.campus].value
        except KeyError:
            raise ValueError(f"Invalid campus code: {campus}. Must be 'WCU'.")
        
        self.student_id = student_id
        self.password = password
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
            'Referer': self.base_url
        })
        self.logged_in = False
        self.on_grade_page = False
        self.login_html = ""
        self.login_url = ""
        self.profile_endpoint = urljoin(self.base_url, "Home")
        self.grade_endpoint = urljoin(self.base_url, "Grade/GradeReport")
        # Session-level caches to speed repeated actions within one login window.
        self._cached_profile = None
        self._cached_grades = None
        self._cached_placement = None
        self._timeout = 12

    @staticmethod
    def _looks_like_grade_page(text: str) -> bool:
        lowered = text.lower()
        markers = [
            "academic status",
            "cgpa",
            "course",
            "grade",
            "result",
            "semester",
        ]
        return any(marker in lowered for marker in markers)

    @staticmethod
    def _looks_like_profile_page(text: str) -> bool:
        lowered = text.lower()
        markers = [
            "full name",
            "id no",
            "department",
            "year",
            "profile",
        ]
        return any(marker in lowered for marker in markers)

    def _collect_link_candidates(self, html: str, prefer: List[str]) -> List[str]:
        """Collect likely endpoints from page links, prioritized by keyword matches."""
        soup = BeautifulSoup(html, "html.parser")
        all_links: List[str] = []
        for anchor in soup.find_all("a"):
            href = anchor.get("href")
            if not href:
                continue
            full = urljoin(self.base_url, href)
            parsed = urlparse(full)
            # Stay inside same portal host.
            if parsed.netloc and urlparse(self.base_url).netloc not in parsed.netloc:
                continue
            all_links.append(full)

        unique_links = []
        seen = set()
        for link in all_links:
            if link not in seen:
                seen.add(link)
                unique_links.append(link)

        preferred = []
        others = []
        for link in unique_links:
            lowered = link.lower()
            if any(keyword in lowered for keyword in prefer):
                preferred.append(link)
            else:
                others.append(link)
        return preferred + others

    @staticmethod
    def _normalize_label(label: str) -> str:
        normalized = re.sub(r"[^a-z0-9]+", " ", label.lower()).strip()
        return re.sub(r"\s+", " ", normalized)

    def _extract_profile_fields(self, soup: BeautifulSoup) -> Dict[str, str]:
        """Extract profile fields from different portal layouts (table/cards/plain text)."""
        extracted: Dict[str, str] = {}

        # 1) Table-based layouts.
        for row in soup.find_all("tr"):
            cells = row.find_all(["td", "th"])
            if len(cells) >= 2:
                key = self._normalize_label(cells[0].get_text(" ", strip=True))
                value = cells[1].get_text(" ", strip=True)
                if key and value:
                    extracted[key] = value

        # 2) Generic "Label: Value" style blocks.
        label_like = soup.find_all(["label", "strong", "b", "span", "div", "p"])
        for tag in label_like:
            text = tag.get_text(" ", strip=True)
            if ":" in text:
                left, right = text.split(":", 1)
                key = self._normalize_label(left)
                value = right.strip()
                if key and value:
                    extracted[key] = value

        # 3) Plain text regex fallback.
        body_text = soup.get_text("\n", strip=True)
        patterns = {
            "full name": r"full\s*name\s*[:\-]\s*([^\n]+)",
            "id no": r"(?:id\s*no|id\s*number|student\s*id)\s*[:\-]\s*([^\n]+)",
            "department": r"department\s*[:\-]\s*([^\n]+)",
            "year": r"year\s*[:\-]\s*([^\n]+)",
            "placed department": r"(?:placed|placement)\s*department\s*[:\-]\s*([^\n]+)",
        }
        for key, pattern in patterns.items():
            if key not in extracted:
                match = re.search(pattern, body_text, re.IGNORECASE)
                if match:
                    extracted[key] = match.group(1).strip()

        return extracted

    @staticmethod
    def _get_first_available(data: Dict[str, str], aliases: List[str], default: str = "N/A") -> str:
        for alias in aliases:
            if alias in data and data[alias]:
                return data[alias]
        return default

    def login(self) -> Union[str, None]:
        """
        Log in to the portal and set session state.
        
        Returns:
            Union[str, None]: None if successful, error message if failed.
        """
        try:
            login_page_url = self.base_url
            response = self.session.get(login_page_url, verify=False, timeout=self._timeout)
            soup = BeautifulSoup(response.text, 'html.parser')

            payload = {
                "UserName": self.student_id,
                "Password": self.password,
                "button": "login",
            }

            for hidden in soup.find_all('input', type='hidden'):
                name = hidden.get('name')
                value = hidden.get('value', '')
                if name:
                    payload[name] = value

            form = soup.find("form")
            action = form.get("action") if form else "/login"
            login_endpoint = urljoin(self.base_url, action)
            resp = self.session.post(
                login_endpoint,
                data=payload,
                verify=False,
                timeout=self._timeout,
                allow_redirects=True
            )

            final_url = resp.url
            self.login_html = resp.text
            self.login_url = final_url

            error_soup = BeautifulSoup(resp.text, 'html.parser')
            error_elem = (
                error_soup.find('span', class_='field-validation-error') or
                error_soup.find('div', class_='validation-summary-errors') or
                error_soup.find('span', class_='text-danger') or
                error_soup.find('div', class_='text-danger') or
                error_soup.find('li', string=lambda t: t and 'incorrect' in t.lower())
            )
            if error_elem:
                error_text = error_elem.get_text(strip=True)
                if 'locked' in error_text.lower():
                    return "Your account has been locked out for 15 minutes due to multiple failed login attempts."
                if 'attempt' in error_text:
                    return error_text
                return error_text or "Invalid credentials."

            final_lower = final_url.lower()
            if "/home" in final_lower or "/grade/gradereport" in final_lower:
                self.logged_in = True
                self.on_grade_page = "/grade/gradereport" in final_lower
                return None

            # WCU returns login pages when authentication fails.
            if "/login" in final_lower or "/account/login" in final_lower:
                return "Incorrect username or password."

            self.logged_in = True
            self.on_grade_page = self._looks_like_grade_page(resp.text) or ("grade" in final_url.lower() or "result" in final_url.lower())
            return None

        except requests.exceptions.Timeout:
            return "Failed to connect to the portal: request timed out."
        except requests.exceptions.ConnectionError:
            return "Failed to connect to the portal: connection error."
        except Exception as e:
            return f"Login failed: {str(e)}"

    def get_profile(self) -> Union[str, Tuple[str, str]]:
        """
        Retrieve the student's profile information.
        
        Returns:
            Union[str, Tuple[str, str]]: 
                - Tuple of (image_url, profile_details) if successful.
                - "GRADUATED" if student is graduated.
                - Error message if failed.
        """
        if self._cached_profile is not None:
            return self._cached_profile

        if not self.logged_in:
            login_result = self.login()
            if login_result is not None:
                return login_result

        try:
            profile_resp = self.session.get(self.profile_endpoint, verify=False, timeout=self._timeout)
            if profile_resp.status_code != 200:
                return f"Profile fetch failed: HTTP {profile_resp.status_code}."
            if "/account/login" in profile_resp.url.lower() or "/login" in profile_resp.url.lower():
                return "Profile fetch failed: session expired or login failed."

            # Fallback to candidate discovery only when default endpoint shape changes.
            if not self._looks_like_profile_page(profile_resp.text):
                candidates = self._collect_link_candidates(
                    self.login_html,
                    prefer=["home", "profile", "dashboard", "student"]
                )
                for candidate in candidates:
                    resp = self.session.get(candidate, verify=False, timeout=self._timeout)
                    if resp.status_code == 200 and self._looks_like_profile_page(resp.text):
                        profile_resp = resp
                        break

            soup = BeautifulSoup(profile_resp.text, 'html.parser')

            img_tag = soup.find('img', class_='img-rounded')
            if img_tag and img_tag.get('src'):
                src = img_tag['src']
                if src.startswith(('http://', 'https://')):
                    image_url = src
                else:
                    image_url = self.base_url.rstrip('/') + '/' + src.lstrip('/')
            else:
                image_url = "https://i.imgur.com/6zZ9b8Z.png"

            data = self._extract_profile_fields(soup)

            full_name = self._get_first_available(
                data, ["full name", "student name", "name", "applicant name"]
            )
            student_id = self._get_first_available(
                data, ["id no", "id number", "student id", "idno"]
            )
            department = self._get_first_available(
                data, ["department", "placed department", "placement department", "program", "stream"]
            )
            year = self._get_first_available(
                data, ["year", "batch", "academic year", "study year"]
            )

            profile_details = (
                f"Full Name: {full_name}\n"
                f"ID No.: {student_id}\n"
                f"Department: {department}\n"
                f"Year: {year}"
            )
            self._cached_profile = (image_url, profile_details)
            return self._cached_profile

        except Exception as e:
            return f"Profile fetch failed: {str(e)}"

    def get_grades(self) -> Union[str, List[str]]:
        """
        Retrieve the student's grades, organized by semesters.
        
        Returns:
            Union[str, List[str]]: List of semester grade strings if successful, error message if failed.
        """
        if self._cached_grades is not None:
            return self._cached_grades

        if not self.logged_in:
            login_result = self.login()
            if login_result is not None:
                return login_result

        try:
            grade_resp = self.session.get(self.grade_endpoint, verify=False, timeout=self._timeout)
            if grade_resp.status_code != 200:
                return f"Grades fetch failed: HTTP {grade_resp.status_code}."
            if "/account/login" in grade_resp.url.lower() or "/login" in grade_resp.url.lower():
                return "Grades fetch failed: session expired or login failed."

            # Fallback to discovery only if grade page layout moved.
            if not self._looks_like_grade_page(grade_resp.text):
                candidates = self._collect_link_candidates(
                    self.login_html,
                    prefer=["grade", "result", "transcript", "report"]
                )
                for candidate in candidates:
                    resp = self.session.get(candidate, verify=False, timeout=self._timeout)
                    if resp.status_code == 200 and self._looks_like_grade_page(resp.text):
                        grade_resp = resp
                        break

            soup = BeautifulSoup(grade_resp.text, 'html.parser')

            semesters: List[str] = []
            current_semester: List[str] = []
            skip_phrases = {'Assessment'}
            skip_values = {'1', '2', '3', '4', '5', '6', '7', '8', '9', '2.00', '3.00', '4.00', '5.00'}

            for td in soup.find_all('td'):
                text = td.get_text(strip=True)
                if not text:
                    continue
                if text in skip_values or any(phrase in text for phrase in skip_phrases):
                    continue
                current_semester.append(text)
                if 'Academic Status' in text or 'CGPA' in text:
                    if current_semester:
                        semesters.append("\n".join(current_semester))
                        current_semester = []

            if current_semester:
                semesters.append("\n".join(current_semester))

            self._cached_grades = semesters if semesters else ["No grades available yet."]
            return self._cached_grades

        except Exception as e:
            return f"Grades fetch failed: {str(e)}"

    def get_placed_department(self) -> str:
        """
        Fetch the student's placed department (useful for freshman placement checks).
        """
        if self._cached_placement is not None:
            return self._cached_placement

        if not self.logged_in:
            login_result = self.login()
            if login_result is not None:
                return login_result

        endpoints = [
            self.profile_endpoint,
            urljoin(self.base_url, "NewStudents/Profile"),
            urljoin(self.base_url, "NewStudents/Welcome"),
        ]

        seen = set()
        for endpoint in endpoints:
            if endpoint in seen:
                continue
            seen.add(endpoint)

            try:
                resp = self.session.get(endpoint, verify=False, timeout=self._timeout)
            except Exception:
                continue

            if resp.status_code != 200:
                continue
            if "/account/login" in resp.url.lower() or "/login" in resp.url.lower():
                continue

            soup = BeautifulSoup(resp.text, "html.parser")

            data = self._extract_profile_fields(soup)
            dept = self._get_first_available(
                data,
                ["placed department", "placement department", "department", "stream", "program"],
                default=""
            )
            if dept:
                self._cached_placement = f"🏫 Placed Department: {dept}"
                return self._cached_placement

        self._cached_placement = "I couldn't find your placed department yet. It may not be published on the portal."
        return self._cached_placement


def login_to_portal(campus: str, student_id: str, password: str) -> Union[str, None]:
    """Compatibility helper used by the bot module."""
    client = PortalClient(campus=campus, student_id=student_id, password=password)
    return client.login()


def get_profile(campus: str, student_id: str, password: str) -> Union[str, Tuple[str, str]]:
    """Compatibility helper used by the bot module."""
    client = PortalClient(campus=campus, student_id=student_id, password=password)
    result = client.get_profile()
    if result == "GRADUATED":
        return "It seems you are a graduate, so I am skipping your profile and showing your grade report below."
    return result


def get_grades(campus: str, student_id: str, password: str) -> Union[str, List[str]]:
    """Compatibility helper used by the bot module."""
    client = PortalClient(campus=campus, student_id=student_id, password=password)
    return client.get_grades()
