import streamlit as st
import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
import seaborn as sns
from sklearn.tree import DecisionTreeClassifier

# --- CONFIG Halaman ---
st.set_page_config(
    page_title="GDRS - Green Dormitory Reporting System",
    page_icon="🌱",
    layout="wide"
)

# --- SIMULASI DATA & MODEL TRAINING (Agar Aplikasi Mandiri) ---
# Diambil berdasarkan logika pada GDRS_v5.ipynb
@st.cache_data
def load_initial_data():
    categories = ['Lampu Rusak', 'Kebocoran Air', 'WiFi Bermasalah', 'Kabel Listrik', 
                  'Kamar Mandi Kotor', 'Saluran Tersumbat', 'Sampah Menumpuk', 'AC Bermasalah']
    locations = ['Gedung A', 'Gedung B', 'Gedung C', 'Kamar Mandi Utama', 'Kantin', 'Koridor']
    
    np.random.seed(42)
    n_samples = 300
    
    data = {
        'id_laporan': [f"LP-{i:03d}" for i in range(1, n_samples + 1)],
        'kategori': np.random.choice(categories, n_samples),
        'lokasi': np.random.choice(locations, n_samples),
        'urgensi': np.random.randint(1, 6, n_samples),
        'dampak_lingkungan': np.random.randint(1, 6, n_samples),
        'jumlah_laporan_serupa': np.random.randint(1, 11, n_samples)
    }
    
    df = pd.DataFrame(data)
    
    # Hitung skor dasar + noise seperti di notebook
    skor_dasar = (df['urgensi'] * 3) + (df['dampak_lingkungan'] * 2.5) + (df['jumlah_laporan_serupa'] * 1.5)
    noise = np.random.randint(-2, 3, n_samples)
    df['skor_total'] = skor_dasar + noise
    
    # Labeling prioritas
    def hitung_prioritas(skor):
        if skor <= 18: return 'Low'
        elif skor <= 28: return 'Medium'
        else: return 'High'
        
    df['prioritas'] = df['skor_total'].apply(hitung_prioritas)
    return df

df_laporan = load_initial_data()

# Training Decision Tree sederhana untuk keperluan prediksi di aplikasi
X = df_laporan[['urgensi', 'dampak_lingkungan', 'jumlah_laporan_serupa']]
y = df_laporan['prioritas']
model = DecisionTreeClassifier(max_depth=4, random_state=42)
model.fit(X, y)


# --- UI STREAMLIT ---
st.title("🌱 Green Dormitory Reporting System (GDRS)")
st.markdown("### Sistem Pelaporan & Prediksi Prioritas Kerusakan Fasilitas Asrama")
st.write("---")

# Pembuatan Sidebar Menu
menu = st.sidebar.selectbox("Pilih Menu:", ["Dashboard Analisis", "Input Laporan Baru"])

if menu == "Dashboard Analisis":
    st.header("📊 Ringkasan Laporan Fasilitas")
    
    # KPI Metrics
    col1, col2, col3, col4 = st.columns(4)
    with col1:
        st.metric("Total Laporan", len(df_laporan))
    with col2:
        st.metric("Prioritas High🔴", len(df_laporan[df_laporan['prioritas'] == 'High']))
    with col3:
        st.metric("Prioritas Medium🟡", len(df_laporan[df_laporan['prioritas'] == 'Medium']))
    with col4:
        st.metric("Prioritas Low🟢", len(df_laporan[df_laporan['prioritas'] == 'Low']))
        
    st.write("---")
    
    # Grafik Visualisasi
    g_col1, g_col2 = st.columns(2)
    
    with g_col1:
        st.subheader("Distribusi Prioritas Laporan")
        fig, ax = plt.subplots()
        colors = ['#ff4d4d', '#ffcc00', '#33cc33'] # Merah, Kuning, Hijau
        df_laporan['prioritas'].value_counts().plot(kind='bar', color=colors, ax=ax)
        ax.set_ylabel("Jumlah Laporan")
        ax.set_xlabel("Tingkat Prioritas")
        plt.xticks(rotation=0)
        st.pyplot(fig)
        
    with g_col2:
        st.subheader("Jumlah Masalah Berdasarkan Kategori")
        fig, ax = plt.subplots()
        df_laporan['kategori'].value_counts().plot(kind='barh', color='#4CAF50', ax=ax)
        ax.set_xlabel("Jumlah Laporan")
        plt.gca().invert_yaxis()
        st.pyplot(fig)
        
    st.write("---")
    st.subheader("📄 Seluruh Data Laporan")
    st.dataframe(df_laporan, use_container_width=True)

elif menu == "Input Laporan Baru":
    st.header("📝 Formulir Pelaporan Masalah & Prediksi Otomatis")
    st.write("Silakan masukkan detail masalah fasilitas asrama di bawah ini:")
    
    # Form Input
    with st.form("form_laporan"):
        col_form1, col_form2 = st.columns(2)
        
        with col_form1:
            kategori = st.selectbox("Kategori Masalah", ['Lampu Rusak', 'Kebocoran Air', 'WiFi Bermasalah', 'Kabel Listrik', 'Kamar Mandi Kotor', 'Saluran Tersumbat', 'Sampah Menumpuk', 'AC Bermasalah'])
            lokasi = st.selectbox("Lokasi Spesifik", ['Gedung A', 'Gedung B', 'Gedung C', 'Kamar Mandi Utama', 'Kantin', 'Koridor'])
            jumlah_serupa = st.slider("Jumlah Laporan Serupa (Skala 1-10)", 1, 10, 1)
            
        with col_form2:
            urgensi = st.slider("Tingkat Urgensi Dampak Langsung (1 = Sangat Rendah, 5 = Sangat Tinggi)", 1, 5, 3)
            dampak_lingkungan = st.slider("Skala Dampak Lingkungan / Pemborosan Energi (1 = Kecil, 5 = Besar)", 1, 5, 3)
            
        submit_button = st.form_submit_button(label="Kirim Laporan & Hitung Prioritas")
        
    if submit_button:
        # Lakukan prediksi dengan model Decision Tree
        input_data = np.array([[urgensi, dampak_lingkungan, jumlah_serupa]])
        prediksi_prioritas = model.predict(input_data)[0]
        
        # Hitung kalkulasi skor manual berdasarkan rumus di notebook untuk transparansi
        skor_hitung = (urgensi * 3) + (dampak_lingkungan * 2.5) + (jumlah_serupa * 1.5)
        
        # Tampilan hasil
        st.success("✅ Laporan Berhasil Dikirim!")
        
        res_col1, res_col2 = st.columns(2)
        with res_col1:
            st.markdown(f"**Detail Laporan:**")
            st.write(f"- Kategori: {kategori}")
            st.write(f"- Lokasi: {lokasi}")
            st.write(f"- Estimasi Skor Sistem: **{skor_hitung}**")
            
        with res_col2:
            st.markdown(f"**Hasil Klasifikasi AI (Decision Tree):**")
            if prediksi_prioritas == 'High':
                st.error(f"🚨 PRIORITAS: {prediksi_prioritas} (Butuh Penanganan Segera!)")
            elif prediksi_prioritas == 'Medium':
                st.warning(f"🟡 PRIORITAS: {prediksi_prioritas} (Djadwalkan Penanganan Berkala)")
            else:
                st.info(f"🟢 PRIORITAS: {prediksi_prioritas} (Penanganan Rutin)")