package pe.syscon.auth.infrastructure;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.auth.domain.Role;
public interface RoleRepository extends JpaRepository<Role,String> {}
